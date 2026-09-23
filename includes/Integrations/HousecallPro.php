<?php
/**
 * Housecall Pro API client for customers and leads.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Integrations;

use SurgeEvaluationPopup\Plugin;
use SurgeEvaluationPopup\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates (or reuses) a customer and then a lead in Housecall Pro.
 */
final class HousecallPro {
	/**
	 * API base URL.
	 */
	private const BASE_URL = 'https://api.housecallpro.com';

	/**
	 * Whether an API key is available.
	 */
	public function is_configured(): bool {
		return '' !== Settings::hcp_api_key();
	}

	/**
	 * Push a lead into Housecall Pro.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array{customer_id: string, lead_id: string, reused: bool, notes: string[]}|WP_Error
	 */
	public function push_lead( array $lead ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'surge_eval_hcp_not_configured', __( 'No Housecall Pro API key (setting or HCP_API_KEY constant).', 'surge-evaluation-popup' ) );
		}

		$notes    = array();
		$customer = null;
		$reused   = false;

		if ( Settings::get( 'hcp_reuse_customer' ) ) {
			$customer = $this->find_customer_by_phone( (string) $lead['phone'] );

			if ( is_wp_error( $customer ) ) {
				// A failed search should not block creating the customer.
				$notes[]  = 'Customer search failed: ' . $customer->get_error_message();
				$customer = null;
			} elseif ( null !== $customer ) {
				$reused  = true;
				$notes[] = 'Reused existing customer.';
			}
		}

		if ( null === $customer ) {
			$customer = $this->create_customer( $lead );

			if ( is_wp_error( $customer ) ) {
				return $customer;
			}
		}

		$customer_id = (string) ( $customer['id'] ?? '' );

		if ( '' === $customer_id ) {
			return new WP_Error( 'surge_eval_hcp_no_customer_id', __( 'Housecall Pro did not return a customer ID.', 'surge-evaluation-popup' ) );
		}

		$lead_id = '';

		if ( Settings::get( 'hcp_create_lead' ) ) {
			$created = $this->create_lead( $customer_id, $this->pick_address_id( $customer, (string) $lead['zip'] ), $lead );

			if ( is_wp_error( $created ) ) {
				$created->add_data( array( 'customer_id' => $customer_id ) );

				return $created;
			}

			$lead_id = (string) ( $created['id'] ?? '' );
		}

		return array(
			'customer_id' => $customer_id,
			'lead_id'     => $lead_id,
			'reused'      => $reused,
			'notes'       => $notes,
		);
	}

	/**
	 * Verify the API key by fetching one customer.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'surge_eval_hcp_not_configured', __( 'No Housecall Pro API key (setting or HCP_API_KEY constant).', 'surge-evaluation-popup' ) );
		}

		$result = $this->request( 'GET', '/customers', array( 'page_size' => 1 ) );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Search for a customer whose phone matches.
	 *
	 * @param string $phone Phone digits.
	 * @return array<string, mixed>|null|WP_Error
	 */
	private function find_customer_by_phone( string $phone ) {
		if ( '' === $phone ) {
			return null;
		}

		$result = $this->request(
			'GET',
			'/customers',
			array(
				'q'         => $phone,
				'page_size' => 10,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( (array) ( $result['customers'] ?? array() ) as $customer ) {
			if ( ! is_array( $customer ) ) {
				continue;
			}

			foreach ( array( 'mobile_number', 'home_number', 'work_number' ) as $field ) {
				$digits = self::last_ten_digits( (string) ( $customer[ $field ] ?? '' ) );

				if ( '' !== $digits && $digits === $phone ) {
					return $customer;
				}
			}
		}

		return null;
	}

	/**
	 * Create a customer.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_customer( array $lead ) {
		list( $first, $last ) = self::split_name( (string) $lead['name'] );

		$payload = array(
			'first_name'            => $first,
			'last_name'             => $last,
			'mobile_number'         => (string) $lead['phone'],
			'notifications_enabled' => false,
			'lead_source'           => (string) Settings::get( 'hcp_lead_source' ),
			'tags'                  => $this->tags( ! empty( $lead['is_test'] ) ),
			'notes'                 => $this->note( $lead ),
			'addresses'             => array(
				array_filter(
					array(
						'street'  => '',
						'city'    => (string) ( $lead['geo_city'] ?? '' ),
						'state'   => self::state_code( (string) ( $lead['geo_region'] ?? '' ) ),
						'zip'     => (string) $lead['zip'],
						'country' => 'US',
						'type'    => 'service',
					),
					static function ( $value ): bool {
						return '' !== $value;
					}
				),
			),
		);

		/**
		 * Filter the Housecall Pro customer payload.
		 *
		 * @param array<string, mixed> $payload Customer payload.
		 * @param array<string, mixed> $lead    Lead row.
		 */
		$payload = apply_filters( 'surge_eval_popup_hcp_customer_payload', $payload, $lead );

		$result = $this->request( 'POST', '/customers', array(), $payload );

		// HCP may reject a street-less address; retry without it so the lead is not lost.
		if ( is_wp_error( $result ) && isset( $payload['addresses'] ) && self::is_client_error( $result ) ) {
			Plugin::log( 'Retrying HCP customer without address', $result->get_error_message() );
			unset( $payload['addresses'] );
			$result = $this->request( 'POST', '/customers', array(), $payload );
		}

		return $result;
	}

	/**
	 * Create a lead for a customer.
	 *
	 * @param string               $customer_id Customer ID.
	 * @param string               $address_id  Address ID (may be empty).
	 * @param array<string, mixed> $lead        Lead row.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_lead( string $customer_id, string $address_id, array $lead ) {
		$payload = array(
			'customer_id' => $customer_id,
			'lead_source' => (string) Settings::get( 'hcp_lead_source' ),
			'note'        => $this->note( $lead ),
			'tags'        => $this->tags( ! empty( $lead['is_test'] ) ),
		);

		if ( '' !== $address_id ) {
			$payload['address_id'] = $address_id;
		}

		/**
		 * Filter the Housecall Pro lead payload.
		 *
		 * @param array<string, mixed> $payload Lead payload.
		 * @param array<string, mixed> $lead    Lead row.
		 */
		$payload = apply_filters( 'surge_eval_popup_hcp_lead_payload', $payload, $lead );

		return $this->request( 'POST', '/leads', array(), $payload );
	}

	/**
	 * Choose the customer's address that best matches the ZIP.
	 *
	 * @param array<string, mixed> $customer Customer.
	 * @param string               $zip      ZIP.
	 */
	private function pick_address_id( array $customer, string $zip ): string {
		$addresses = array_values( array_filter( (array) ( $customer['addresses'] ?? array() ), 'is_array' ) );

		foreach ( $addresses as $address ) {
			if ( $zip === (string) ( $address['zip'] ?? '' ) && ! empty( $address['id'] ) ) {
				return (string) $address['id'];
			}
		}

		return isset( $addresses[0]['id'] ) ? (string) $addresses[0]['id'] : '';
	}

	/**
	 * Note text for customer/lead.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 */
	private function note( array $lead ): string {
		$parts = array(
			'Requested the $99 Electrical Safety Evaluation via the website popup.',
			'ZIP: ' . (string) $lead['zip'],
		);

		if ( ! empty( $lead['page_url'] ) ) {
			$parts[] = 'Page: ' . (string) $lead['page_url'];
		}

		if ( ! empty( $lead['is_test'] ) ) {
			array_unshift( $parts, 'TEST SUBMISSION - safe to delete.' );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Tags list.
	 *
	 * @param bool $is_test Whether this is a test lead.
	 * @return string[]
	 */
	private function tags( bool $is_test ): array {
		$tags = Settings::csv( 'hcp_tags' );

		if ( $is_test ) {
			$tags[] = 'Test';
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Perform an API request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $query  Query args.
	 * @param array<string, mixed> $body   JSON body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $method, string $path, array $query = array(), array $body = array() ) {
		$url  = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), self::BASE_URL . $path );
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . Settings::hcp_api_key(),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);

		if ( 'GET' !== $method ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		Plugin::log( sprintf( 'HCP %s %s -> HTTP %d', $method, $path, $code ), array( 'request' => $body, 'response' => substr( $raw, 0, 2000 ) ) );

		if ( $code < 200 || $code >= 300 ) {
			$detail = '';

			if ( is_array( $decoded ) ) {
				$detail = (string) ( $decoded['message'] ?? $decoded['error'] ?? '' );

				if ( '' === $detail && isset( $decoded['errors'] ) ) {
					$detail = wp_json_encode( $decoded['errors'] );
				}
			}

			if ( '' === $detail ) {
				$detail = wp_strip_all_tags( substr( $raw, 0, 300 ) );
			}

			return new WP_Error(
				'surge_eval_hcp_error',
				sprintf( 'Housecall Pro %s %s returned HTTP %d: %s', $method, $path, $code, $detail ),
				array( 'status_code' => $code )
			);
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Whether an error is an HTTP 4xx response.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function is_client_error( WP_Error $error ): bool {
		$data = $error->get_error_data();
		$code = is_array( $data ) ? (int) ( $data['status_code'] ?? 0 ) : 0;

		return $code >= 400 && $code < 500 && 401 !== $code && 403 !== $code;
	}

	/**
	 * Split a full name into first and last.
	 *
	 * @param string $name Full name.
	 * @return array{0: string, 1: string}
	 */
	public static function split_name( string $name ): array {
		$parts = preg_split( '/\s+/', trim( $name ), 2 );

		return array( (string) ( $parts[0] ?? '' ), (string) ( $parts[1] ?? '' ) );
	}

	/**
	 * Last ten digits of a phone string.
	 *
	 * @param string $phone Phone.
	 */
	public static function last_ten_digits( string $phone ): string {
		$digits = (string) preg_replace( '/\D+/', '', $phone );

		return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : $digits;
	}

	/**
	 * Convert a region name to a state code where possible.
	 *
	 * @param string $region Region name or code.
	 */
	private static function state_code( string $region ): string {
		if ( 2 === strlen( $region ) ) {
			return strtoupper( $region );
		}

		return 'washington' === strtolower( $region ) ? 'WA' : $region;
	}
}
