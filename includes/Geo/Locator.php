<?php
/**
 * IP geolocation and service-area evaluation.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Geo;

use SurgeEvaluationPopup\Plugin;
use SurgeEvaluationPopup\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves an IP to a location and decides whether it is inside the service area.
 */
final class Locator {
	/**
	 * Transient prefix for cached lookups.
	 */
	public const CACHE_PREFIX = 'surge_eval_geo_';

	/**
	 * Look up an IP address.
	 *
	 * @param string $ip           IP address.
	 * @param bool   $use_cache    Whether to read/write the lookup cache.
	 * @param bool   $allow_headers Whether Cloudflare request headers describe this IP (only for the live visitor).
	 * @return array<string, mixed>|WP_Error Normalized location: ip, city, region, region_code, country, lat, lng, provider.
	 */
	public function lookup( string $ip, bool $use_cache = true, bool $allow_headers = false ) {
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'surge_eval_invalid_ip', __( 'No valid IP address.', 'surge-evaluation-popup' ) );
		}

		if ( ! IpResolver::is_public( $ip ) ) {
			return new WP_Error(
				'surge_eval_private_ip',
				sprintf(
					/* translators: %s: IP address. */
					__( '%s is a private/local IP address and cannot be geolocated. Use a test IP instead.', 'surge-evaluation-popup' ),
					$ip
				)
			);
		}

		$provider = (string) Settings::get( 'geo_provider' );

		if ( 'cloudflare' === $provider && $allow_headers ) {
			$from_headers = $this->from_cloudflare_headers( $ip );

			if ( null !== $from_headers ) {
				return $from_headers;
			}
		}

		if ( 'cloudflare' === $provider ) {
			$provider = 'ipinfo';
		}

		$cache_key = self::CACHE_PREFIX . md5( $provider . '|' . $ip );

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				if ( isset( $cached['error'] ) ) {
					return new WP_Error( 'surge_eval_lookup_failed', (string) $cached['error'] );
				}

				$cached['cached'] = true;

				return $cached;
			}
		}

		switch ( $provider ) {
			case 'ipapi_co':
				$result = $this->lookup_ipapi_co( $ip );
				break;
			case 'ip_api':
				$result = $this->lookup_ip_api( $ip );
				break;
			default:
				$result = $this->lookup_ipinfo( $ip );
		}

		Plugin::log( 'Geo lookup ' . $ip . ' via ' . $provider, is_wp_error( $result ) ? $result->get_error_message() : $result );

		if ( $use_cache ) {
			if ( is_wp_error( $result ) ) {
				// Cache failures briefly so a broken provider is not hammered.
				set_transient( $cache_key, array( 'error' => $result->get_error_message() ), 10 * MINUTE_IN_SECONDS );
			} else {
				set_transient( $cache_key, $result, max( 1, (int) Settings::get( 'geo_cache_hours' ) ) * HOUR_IN_SECONDS );
			}
		}

		return $result;
	}

	/**
	 * Decide whether a location is inside the service area.
	 *
	 * @param array<string, mixed> $location Normalized location.
	 * @return array{eligible: bool, reason: string, distance_miles: float|null}
	 */
	public function evaluate( array $location ): array {
		$center_lat = (float) Settings::get( 'center_lat' );
		$center_lng = (float) Settings::get( 'center_lng' );
		$radius     = (float) Settings::get( 'radius_miles' );
		$distance   = null;

		if ( isset( $location['lat'], $location['lng'] ) && is_numeric( $location['lat'] ) && is_numeric( $location['lng'] ) ) {
			$distance = round( self::distance_miles( $center_lat, $center_lng, (float) $location['lat'], (float) $location['lng'] ), 1 );
		}

		$city          = strtolower( trim( (string) ( $location['city'] ?? '' ) ) );
		$allowed_lines = preg_split( '/[\r\n]+/', strtolower( (string) Settings::get( 'allowed_cities' ) ) );
		$allowed       = array_filter( array_map( 'trim', is_array( $allowed_lines ) ? $allowed_lines : array() ) );

		if ( '' !== $city && in_array( $city, $allowed, true ) ) {
			return array(
				'eligible'       => true,
				'reason'         => sprintf( 'City "%s" is in the always-allowed list.', $location['city'] ),
				'distance_miles' => $distance,
			);
		}

		if ( null === $distance ) {
			return array(
				'eligible'       => false,
				'reason'         => 'Provider returned no coordinates.',
				'distance_miles' => null,
			);
		}

		if ( Settings::get( 'restrict_state' ) && ! self::is_washington( $location ) ) {
			return array(
				'eligible'       => false,
				'reason'         => sprintf( 'Outside Washington State (region "%s").', $location['region'] ?? '' ),
				'distance_miles' => $distance,
			);
		}

		if ( $distance > $radius ) {
			return array(
				'eligible'       => false,
				'reason'         => sprintf( '%.1f miles from center, radius is %s miles.', $distance, $radius ),
				'distance_miles' => $distance,
			);
		}

		return array(
			'eligible'       => true,
			'reason'         => sprintf( '%.1f miles from center, within the %s mile radius.', $distance, $radius ),
			'distance_miles' => $distance,
		);
	}

	/**
	 * Full check: look up and evaluate an IP, applying the failure policy.
	 *
	 * @param string $ip            IP address.
	 * @param bool   $allow_headers Whether Cloudflare headers describe this IP.
	 * @return array{eligible: bool, reason: string, distance_miles: float|null, location: array<string, mixed>|null}
	 */
	public function check( string $ip, bool $allow_headers = false ): array {
		$location = $this->lookup( $ip, true, $allow_headers );

		if ( is_wp_error( $location ) ) {
			return array(
				'eligible'       => 'show' === Settings::get( 'lookup_failure' ),
				'reason'         => 'Lookup failed: ' . $location->get_error_message(),
				'distance_miles' => null,
				'location'       => null,
			);
		}

		return array_merge( $this->evaluate( $location ), array( 'location' => $location ) );
	}

	/**
	 * Delete all cached lookups.
	 */
	public static function clear_cache(): int {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		$like_timeout = $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $like_timeout ) );

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'transient' );
		}

		return $deleted;
	}

	/**
	 * Great-circle distance in miles.
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lng1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lng2 Longitude 2.
	 */
	public static function distance_miles( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$earth_radius = 3958.8;
		$d_lat        = deg2rad( $lat2 - $lat1 );
		$d_lng        = deg2rad( $lng2 - $lng1 );
		$a            = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

		return $earth_radius * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * Whether a location is in Washington State.
	 *
	 * @param array<string, mixed> $location Normalized location.
	 */
	private static function is_washington( array $location ): bool {
		$country = strtoupper( (string) ( $location['country'] ?? '' ) );

		if ( '' !== $country && 'US' !== $country ) {
			return false;
		}

		$code   = strtoupper( (string) ( $location['region_code'] ?? '' ) );
		$region = strtolower( (string) ( $location['region'] ?? '' ) );

		return 'WA' === $code || 'washington' === $region;
	}

	/**
	 * Build a location from Cloudflare visitor location headers.
	 *
	 * @param string $ip Visitor IP.
	 * @return array<string, mixed>|null
	 */
	private function from_cloudflare_headers( string $ip ): ?array {
		$lat = isset( $_SERVER['HTTP_CF_IPLATITUDE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPLATITUDE'] ) ) : '';
		$lng = isset( $_SERVER['HTTP_CF_IPLONGITUDE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPLONGITUDE'] ) ) : '';

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}

		return array(
			'ip'          => $ip,
			'city'        => isset( $_SERVER['HTTP_CF_IPCITY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCITY'] ) ) : '',
			'region'      => isset( $_SERVER['HTTP_CF_REGION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_REGION'] ) ) : '',
			'region_code' => isset( $_SERVER['HTTP_CF_REGION_CODE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_REGION_CODE'] ) ) : '',
			'country'     => isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) : '',
			'lat'         => (float) $lat,
			'lng'         => (float) $lng,
			'provider'    => 'cloudflare',
		);
	}

	/**
	 * Look up via ipinfo.io.
	 *
	 * @param string $ip IP address.
	 * @return array<string, mixed>|WP_Error
	 */
	private function lookup_ipinfo( string $ip ) {
		$url   = 'https://ipinfo.io/' . rawurlencode( $ip ) . '/json';
		$token = (string) Settings::get( 'ipinfo_token' );

		if ( '' !== $token ) {
			$url = add_query_arg( 'token', rawurlencode( $token ), $url );
		}

		$data = $this->fetch_json( $url );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['error'] ) ) {
			$message = is_array( $data['error'] ) ? (string) ( $data['error']['message'] ?? 'error' ) : (string) $data['error'];

			return new WP_Error( 'surge_eval_lookup_failed', 'ipinfo: ' . $message );
		}

		$loc = explode( ',', (string) ( $data['loc'] ?? '' ) );

		return array(
			'ip'          => $ip,
			'city'        => (string) ( $data['city'] ?? '' ),
			'region'      => (string) ( $data['region'] ?? '' ),
			'region_code' => '',
			'country'     => (string) ( $data['country'] ?? '' ),
			'lat'         => isset( $loc[1] ) ? (float) $loc[0] : null,
			'lng'         => isset( $loc[1] ) ? (float) $loc[1] : null,
			'postal'      => (string) ( $data['postal'] ?? '' ),
			'provider'    => 'ipinfo',
		);
	}

	/**
	 * Look up via ipapi.co.
	 *
	 * @param string $ip IP address.
	 * @return array<string, mixed>|WP_Error
	 */
	private function lookup_ipapi_co( string $ip ) {
		$data = $this->fetch_json( 'https://ipapi.co/' . rawurlencode( $ip ) . '/json/' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! empty( $data['error'] ) ) {
			return new WP_Error( 'surge_eval_lookup_failed', 'ipapi.co: ' . (string) ( $data['reason'] ?? 'error' ) );
		}

		return array(
			'ip'          => $ip,
			'city'        => (string) ( $data['city'] ?? '' ),
			'region'      => (string) ( $data['region'] ?? '' ),
			'region_code' => (string) ( $data['region_code'] ?? '' ),
			'country'     => (string) ( $data['country_code'] ?? '' ),
			'lat'         => isset( $data['latitude'] ) ? (float) $data['latitude'] : null,
			'lng'         => isset( $data['longitude'] ) ? (float) $data['longitude'] : null,
			'postal'      => (string) ( $data['postal'] ?? '' ),
			'provider'    => 'ipapi_co',
		);
	}

	/**
	 * Look up via ip-api.com (free tier is HTTP only).
	 *
	 * @param string $ip IP address.
	 * @return array<string, mixed>|WP_Error
	 */
	private function lookup_ip_api( string $ip ) {
		$data = $this->fetch_json( 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,message,countryCode,region,regionName,city,zip,lat,lon' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( 'success' !== ( $data['status'] ?? '' ) ) {
			return new WP_Error( 'surge_eval_lookup_failed', 'ip-api: ' . (string) ( $data['message'] ?? 'error' ) );
		}

		return array(
			'ip'          => $ip,
			'city'        => (string) ( $data['city'] ?? '' ),
			'region'      => (string) ( $data['regionName'] ?? '' ),
			'region_code' => (string) ( $data['region'] ?? '' ),
			'country'     => (string) ( $data['countryCode'] ?? '' ),
			'lat'         => isset( $data['lat'] ) ? (float) $data['lat'] : null,
			'lng'         => isset( $data['lon'] ) ? (float) $data['lon'] : null,
			'postal'      => (string) ( $data['zip'] ?? '' ),
			'provider'    => 'ip_api',
		);
	}

	/**
	 * GET a URL and decode JSON.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>|WP_Error
	 */
	private function fetch_json( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => 'SurgeEvaluationPopup/' . SURGE_EVAL_POPUP_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'surge_eval_lookup_failed', sprintf( 'Provider returned HTTP %d with invalid JSON.', $code ) );
		}

		if ( 429 === $code ) {
			return new WP_Error( 'surge_eval_lookup_failed', 'Provider rate limit reached (HTTP 429).' );
		}

		return $decoded;
	}
}
