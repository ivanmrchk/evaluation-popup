<?php
/**
 * Visitor IP detection.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Geo;

use SurgeEvaluationPopup\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines the visitor's IP address.
 */
final class IpResolver {
	/**
	 * Get the current visitor IP, or an empty string when none is valid.
	 */
	public static function visitor_ip(): string {
		$candidates = array();

		if ( Settings::get( 'trust_proxy' ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) {
					$parts        = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
					$candidates[] = trim( $parts[0] );
				}
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		foreach ( $candidates as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Whether an IP is public (not private, loopback or reserved).
	 *
	 * @param string $ip IP address.
	 */
	public static function is_public( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}
