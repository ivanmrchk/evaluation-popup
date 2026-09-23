<?php
/**
 * Lead submission pipeline.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Leads;

use SurgeEvaluationPopup\Database\LeadRepository;
use SurgeEvaluationPopup\Integrations\HousecallPro;
use SurgeEvaluationPopup\Integrations\Mailgun;
use SurgeEvaluationPopup\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates, stores and delivers popup submissions.
 */
final class SubmissionHandler {
	/**
	 * Repository.
	 *
	 * @var LeadRepository
	 */
	private LeadRepository $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new LeadRepository();
	}

	/**
	 * Validate and normalize raw input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{name: string, phone: string, zip: string}|WP_Error
	 */
	public function validate( array $input ) {
		$name  = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$phone = HousecallPro::last_ten_digits( (string) ( $input['phone'] ?? '' ) );
		$zip   = substr( (string) preg_replace( '/\D+/', '', (string) ( $input['zip'] ?? '' ) ), 0, 5 );

		// Strip a leading US country code that last_ten_digits would otherwise keep for 11-digit input.
		$raw_digits = (string) preg_replace( '/\D+/', '', (string) ( $input['phone'] ?? '' ) );

		if ( strlen( $raw_digits ) > 11 || ( 11 === strlen( $raw_digits ) && '1' !== $raw_digits[0] ) ) {
			$phone = '';
		}

		$errors = array();

		if ( mb_strlen( $name ) < 2 ) {
			$errors['name'] = __( 'Please enter your name.', 'surge-evaluation-popup' );
		}

		if ( 10 !== strlen( $phone ) ) {
			$errors['phone'] = __( 'Please enter a valid 10-digit phone number.', 'surge-evaluation-popup' );
		}

		if ( 5 !== strlen( $zip ) ) {
			$errors['zip'] = __( 'Please enter a 5-digit ZIP code.', 'surge-evaluation-popup' );
		}

		if ( $errors ) {
			return new WP_Error( 'surge_eval_invalid', implode( ' ', $errors ), array( 'status' => 400, 'fields' => $errors ) );
		}

		return array(
			'name'  => mb_substr( $name, 0, 191 ),
			'phone' => $phone,
			'zip'   => $zip,
		);
	}

	/**
	 * Store a lead and deliver it.
	 *
	 * @param array{name: string, phone: string, zip: string} $fields Validated fields.
	 * @param array<string, mixed>                            $meta   ip, geo_city, geo_region, page_url, referrer, is_test.
	 * @return array<string, mixed>|WP_Error Final lead row.
	 */
	public function submit( array $fields, array $meta = array() ) {
		$id = $this->repository->insert(
			array(
				'name'       => $fields['name'],
				'phone'      => $fields['phone'],
				'zip'        => $fields['zip'],
				'ip'         => (string) ( $meta['ip'] ?? '' ),
				'geo_city'   => (string) ( $meta['geo_city'] ?? '' ),
				'geo_region' => (string) ( $meta['geo_region'] ?? '' ),
				'page_url'   => esc_url_raw( (string) ( $meta['page_url'] ?? '' ) ),
				'referrer'   => esc_url_raw( (string) ( $meta['referrer'] ?? '' ) ),
				'is_test'    => empty( $meta['is_test'] ) ? 0 : 1,
			)
		);

		if ( 0 === $id ) {
			return new WP_Error( 'surge_eval_db', __( 'Could not save your request. Please call us instead.', 'surge-evaluation-popup' ), array( 'status' => 500 ) );
		}

		$lead = $this->deliver( $id );

		/**
		 * Fires after a popup lead was stored and delivered.
		 *
		 * @param array<string, mixed> $lead Lead row.
		 */
		do_action( 'surge_eval_popup_lead_submitted', $lead );

		return $lead;
	}

	/**
	 * Run (or re-run) the integrations for a stored lead. Already-successful steps are skipped.
	 *
	 * @param int $id Lead ID.
	 * @return array<string, mixed> Updated lead row.
	 */
	public function deliver( int $id ): array {
		$lead = $this->repository->find( $id );

		if ( null === $lead ) {
			return array();
		}

		// Housecall Pro first so the email can link to the customer.
		if ( 'success' !== $lead['hcp_status'] ) {
			$update = $this->run_hcp( $lead );
			$this->repository->update( $id, $update );
			$lead = array_merge( $lead, $update );
		}

		if ( 'success' !== $lead['mailgun_status'] ) {
			$update = $this->run_mailgun( $lead );
			$this->repository->update( $id, $update );
			$lead = array_merge( $lead, $update );
		}

		return $lead;
	}

	/**
	 * Push to Housecall Pro.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, string>
	 */
	private function run_hcp( array $lead ): array {
		if ( ! Settings::get( 'hcp_enabled' ) ) {
			return array(
				'hcp_status'  => 'skipped',
				'hcp_message' => 'Housecall Pro push is disabled.',
			);
		}

		$result = ( new HousecallPro() )->push_lead( $lead );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			return array(
				'hcp_status'      => 'error',
				'hcp_message'     => $result->get_error_message(),
				'hcp_customer_id' => is_array( $data ) && isset( $data['customer_id'] ) ? (string) $data['customer_id'] : (string) $lead['hcp_customer_id'],
			);
		}

		$message = $result['notes'];
		$message[] = '' !== $result['lead_id'] ? 'Customer and lead created.' : 'Customer saved (lead creation disabled).';

		return array(
			'hcp_status'      => 'success',
			'hcp_message'     => implode( ' ', $message ),
			'hcp_customer_id' => $result['customer_id'],
			'hcp_lead_id'     => $result['lead_id'],
		);
	}

	/**
	 * Send the Mailgun notification.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, string>
	 */
	private function run_mailgun( array $lead ): array {
		if ( ! Settings::get( 'mailgun_enabled' ) ) {
			return array(
				'mailgun_status'  => 'skipped',
				'mailgun_message' => 'Mailgun email is disabled.',
			);
		}

		$result = ( new Mailgun() )->send_lead( $lead );

		if ( is_wp_error( $result ) ) {
			return array(
				'mailgun_status'  => 'error',
				'mailgun_message' => $result->get_error_message(),
			);
		}

		return array(
			'mailgun_status'  => 'success',
			'mailgun_message' => 'Sent: ' . $result,
		);
	}
}
