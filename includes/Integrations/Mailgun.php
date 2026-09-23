<?php
/**
 * Mailgun API client.
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
 * Sends lead notification emails through the Mailgun Messages API.
 */
final class Mailgun {
	/**
	 * Whether all required settings are present.
	 */
	public function is_configured(): bool {
		return '' !== Settings::mailgun_api_key()
			&& '' !== (string) Settings::get( 'mailgun_domain' )
			&& '' !== (string) Settings::get( 'mailgun_from_email' )
			&& array() !== Settings::csv( 'mailgun_to' );
	}

	/**
	 * Send the notification email for a lead.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return string|WP_Error Mailgun message ID on success.
	 */
	public function send_lead( array $lead ) {
		$replacements = array(
			'{name}'  => (string) $lead['name'],
			'{phone}' => self::format_phone( (string) $lead['phone'] ),
			'{zip}'   => (string) $lead['zip'],
			'{city}'  => (string) ( $lead['geo_city'] ?? '' ),
		);

		$subject = strtr( (string) Settings::get( 'mailgun_subject' ), $replacements );

		if ( ! empty( $lead['is_test'] ) ) {
			$subject = '[TEST] ' . $subject;
		}

		return $this->send( $subject, $this->text_body( $lead ), $this->html_body( $lead ) );
	}

	/**
	 * Send an arbitrary message.
	 *
	 * @param string $subject Subject.
	 * @param string $text    Plain-text body.
	 * @param string $html    HTML body.
	 * @return string|WP_Error Mailgun message ID on success.
	 */
	public function send( string $subject, string $text, string $html = '' ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'surge_eval_mailgun_not_configured', __( 'Mailgun is missing an API key, domain, from address or recipient.', 'surge-evaluation-popup' ) );
		}

		$host = 'eu' === Settings::get( 'mailgun_region' ) ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
		$url  = $host . '/v3/' . rawurlencode( (string) Settings::get( 'mailgun_domain' ) ) . '/messages';
		$from = (string) Settings::get( 'mailgun_from_email' );
		$name = (string) Settings::get( 'mailgun_from_name' );

		$body = array(
			'from'    => '' !== $name ? sprintf( '%s <%s>', str_replace( array( '<', '>', '"' ), '', $name ), $from ) : $from,
			'to'      => implode( ',', Settings::csv( 'mailgun_to' ) ),
			'subject' => $subject,
			'text'    => $text,
		);

		if ( '' !== $html ) {
			$body['html'] = $html;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Authorization' => 'Basic ' . base64_encode( 'api:' . Settings::mailgun_api_key() ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		Plugin::log( 'Mailgun HTTP ' . $code, $raw );

		if ( 200 !== $code ) {
			$message = is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : wp_strip_all_tags( substr( $raw, 0, 200 ) );

			return new WP_Error( 'surge_eval_mailgun_error', sprintf( 'Mailgun HTTP %d: %s', $code, $message ) );
		}

		return is_array( $decoded ) && isset( $decoded['id'] ) ? (string) $decoded['id'] : 'sent';
	}

	/**
	 * Format a 10-digit US phone number for display.
	 *
	 * @param string $digits Phone digits.
	 */
	public static function format_phone( string $digits ): string {
		if ( 10 === strlen( $digits ) ) {
			return sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) );
		}

		return $digits;
	}

	/**
	 * Email rows for a lead.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, string>
	 */
	private function rows( array $lead ): array {
		$location = trim( implode( ', ', array_filter( array( (string) ( $lead['geo_city'] ?? '' ), (string) ( $lead['geo_region'] ?? '' ) ) ) ) );

		$rows = array(
			'Name'           => (string) $lead['name'],
			'Phone'          => self::format_phone( (string) $lead['phone'] ),
			'ZIP Code'       => (string) $lead['zip'],
			'IP Location'    => '' !== $location ? $location : 'Unknown',
			'Page'           => (string) ( $lead['page_url'] ?? '' ),
			'Referrer'       => (string) ( $lead['referrer'] ?? '' ),
			'Submitted'      => wp_date( 'M j, Y g:i a' ),
		);

		if ( ! empty( $lead['hcp_customer_id'] ) ) {
			$rows['Housecall Pro'] = 'https://pro.housecallpro.com/app/customers/' . rawurlencode( (string) $lead['hcp_customer_id'] );
		} elseif ( ! empty( $lead['hcp_message'] ) && 'error' === ( $lead['hcp_status'] ?? '' ) ) {
			$rows['Housecall Pro'] = 'FAILED: ' . (string) $lead['hcp_message'];
		}

		return array_filter( $rows, 'strlen' );
	}

	/**
	 * Plain-text email body.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 */
	private function text_body( array $lead ): string {
		$lines = array( 'New $99 Electrical Safety Evaluation request', '' );

		foreach ( $this->rows( $lead ) as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * HTML email body.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 */
	private function html_body( array $lead ): string {
		$rows = '';

		foreach ( $this->rows( $lead ) as $label => $value ) {
			if ( 'Phone' === $label ) {
				$value_html = '<a href="tel:' . esc_attr( (string) $lead['phone'] ) . '">' . esc_html( $value ) . '</a>';
			} elseif ( 0 === strpos( $value, 'http' ) ) {
				$value_html = '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>';
			} else {
				$value_html = esc_html( $value );
			}

			$rows .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#666;white-space:nowrap;vertical-align:top;"><strong>' . esc_html( $label ) . '</strong></td>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #eee;color:#111;">' . $value_html . '</td></tr>';
		}

		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;max-width:600px;">'
			. '<h2 style="color:#0f1b2d;margin:0 0 12px;">New $99 Electrical Safety Evaluation request</h2>'
			. '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;">' . $rows . '</table>'
			. '</div>';
	}
}
