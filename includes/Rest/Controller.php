<?php
/**
 * REST endpoints for the popup.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Rest;

use SurgeEvaluationPopup\Geo\IpResolver;
use SurgeEvaluationPopup\Geo\Locator;
use SurgeEvaluationPopup\Leads\SubmissionHandler;
use SurgeEvaluationPopup\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the uncached geo-eligibility and submission endpoints.
 */
final class Controller {
	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'surge-eval/v1';

	/**
	 * Max submissions per IP per hour.
	 */
	private const RATE_LIMIT = 5;

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/geo',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'geo' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'test_ip' => array(
						'type'    => 'string',
						'default' => '',
					),
					'preview' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Should the popup show for this visitor?
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function geo( WP_REST_Request $request ): WP_REST_Response {
		$is_admin = current_user_can( 'manage_options' );
		$test_ip  = trim( (string) $request->get_param( 'test_ip' ) );
		$mode     = (string) Settings::get( 'test_mode' );

		if ( ! Settings::get( 'enabled' ) ) {
			return $this->no_cache( array( 'eligible' => false, 'reason' => 'Popup disabled.' ), $is_admin );
		}

		// Admin-only overrides: force preview, or evaluate a simulated IP.
		if ( $is_admin && '' !== $test_ip ) {
			if ( ! filter_var( $test_ip, FILTER_VALIDATE_IP ) ) {
				return $this->no_cache( array( 'eligible' => false, 'reason' => 'Invalid test IP.' ), true );
			}

			$result           = ( new Locator() )->check( $test_ip );
			$result['ip']     = $test_ip;
			$result['reason'] = '[Test IP] ' . $result['reason'];

			return $this->no_cache( $result, true );
		}

		if ( $is_admin && ( $request->get_param( 'preview' ) || 'admins' === $mode ) ) {
			return $this->no_cache( array( 'eligible' => true, 'reason' => 'Admin preview / test mode.' ), true );
		}

		if ( 'everyone' === $mode ) {
			return $this->no_cache( array( 'eligible' => true, 'reason' => 'Test mode: showing to everyone.' ), $is_admin );
		}

		if ( ! Settings::get( 'geo_enabled' ) ) {
			return $this->no_cache( array( 'eligible' => true, 'reason' => 'Location targeting disabled.' ), $is_admin );
		}

		$ip           = IpResolver::visitor_ip();
		$result       = ( new Locator() )->check( $ip, true );
		$result['ip'] = $ip;

		return $this->no_cache( $result, $is_admin );
	}

	/**
	 * Handle a popup submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) || array() === $params ) {
			$params = $request->get_body_params();
		}

		// Honeypot and minimum fill time: pretend success so bots learn nothing.
		$elapsed = (int) ( $params['elapsed'] ?? 0 );

		if ( ! empty( $params['website'] ) || ( $elapsed > 0 && $elapsed < 1500 ) ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		$ip = IpResolver::visitor_ip();

		if ( ! current_user_can( 'manage_options' ) && $this->rate_limited( $ip ) ) {
			return new WP_Error( 'surge_eval_rate_limited', __( 'Too many requests. Please call us instead.', 'surge-evaluation-popup' ), array( 'status' => 429 ) );
		}

		$handler = new SubmissionHandler();
		$fields  = $handler->validate( $params );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$meta = array(
			'ip'       => $ip,
			'page_url' => (string) ( $params['page_url'] ?? '' ),
			'referrer' => (string) ( $params['referrer'] ?? '' ),
		);

		// Reuse the (cached) geo lookup for context in HCP and the email; never block on it.
		if ( IpResolver::is_public( $ip ) ) {
			$location = ( new Locator() )->lookup( $ip, true, true );

			if ( is_array( $location ) ) {
				$meta['geo_city']   = (string) ( $location['city'] ?? '' );
				$meta['geo_region'] = (string) ( $location['region'] ?? '' );
			}
		}

		$lead = $handler->submit( $fields, $meta );

		if ( is_wp_error( $lead ) ) {
			return $lead;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'title'   => (string) Settings::get( 'success_title' ),
				'message' => (string) Settings::get( 'success_message' ),
			),
			200
		);
	}

	/**
	 * Track and check the per-IP submission rate.
	 *
	 * @param string $ip IP address.
	 */
	private function rate_limited( string $ip ): bool {
		$key   = 'surge_eval_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return false;
	}

	/**
	 * Build an uncacheable response; location details only for admins.
	 *
	 * @param array<string, mixed> $data     Result.
	 * @param bool                 $detailed Include diagnostic details.
	 */
	private function no_cache( array $data, bool $detailed ): WP_REST_Response {
		$body = array( 'eligible' => (bool) $data['eligible'] );

		if ( $detailed ) {
			$body['debug'] = $data;
		}

		$response = new WP_REST_Response( $body, 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

		return $response;
	}
}
