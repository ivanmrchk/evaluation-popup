<?php
/**
 * Plugin settings storage and schema.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores every setting in a single option and describes how each is rendered and sanitized.
 */
final class Settings {
	/**
	 * Option name.
	 */
	public const OPTION = 'surge_eval_popup_settings';

	/**
	 * Cached merged settings for the request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Settings tabs.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'general' => __( 'Display & Triggers', 'surge-evaluation-popup' ),
			'content' => __( 'Content', 'surge-evaluation-popup' ),
			'geo'     => __( 'Location Targeting', 'surge-evaluation-popup' ),
			'mailgun' => __( 'Mailgun', 'surge-evaluation-popup' ),
			'hcp'     => __( 'Housecall Pro', 'surge-evaluation-popup' ),
			'testing' => __( 'Testing', 'surge-evaluation-popup' ),
		);
	}

	/**
	 * Field schema: key => definition.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		return array(
			// Display & triggers.
			'enabled'            => array(
				'tab'     => 'general',
				'type'    => 'checkbox',
				'label'   => __( 'Enable popup', 'surge-evaluation-popup' ),
				'default' => 1,
				'help'    => __( 'Master switch. When off, the [surge_evaluation_popup] shortcode renders nothing.', 'surge-evaluation-popup' ),
			),
			'trigger'            => array(
				'tab'     => 'general',
				'type'    => 'select',
				'label'   => __( 'Open trigger', 'surge-evaluation-popup' ),
				'default' => 'delay_or_exit',
				'options' => array(
					'immediate'     => __( 'Immediately on page load', 'surge-evaluation-popup' ),
					'delay'         => __( 'After a delay', 'surge-evaluation-popup' ),
					'scroll'        => __( 'After scrolling a percentage of the page', 'surge-evaluation-popup' ),
					'exit'          => __( 'Exit intent (desktop) only', 'surge-evaluation-popup' ),
					'delay_or_exit' => __( 'After a delay OR on exit intent (whichever comes first)', 'surge-evaluation-popup' ),
					'manual'        => __( 'Manual only (links/buttons with class "surge-eval-open")', 'surge-evaluation-popup' ),
				),
			),
			'delay_seconds'      => array(
				'tab'     => 'general',
				'type'    => 'number',
				'label'   => __( 'Delay (seconds)', 'surge-evaluation-popup' ),
				'default' => 8,
				'min'     => 0,
			),
			'scroll_percent'     => array(
				'tab'     => 'general',
				'type'    => 'number',
				'label'   => __( 'Scroll depth (%)', 'surge-evaluation-popup' ),
				'default' => 40,
				'min'     => 1,
				'max'     => 100,
			),
			'dismiss_days'       => array(
				'tab'     => 'general',
				'type'    => 'number',
				'label'   => __( 'Hide after close (days)', 'surge-evaluation-popup' ),
				'default' => 7,
				'min'     => 0,
				'help'    => __( 'After a visitor closes the popup, do not auto-open it again for this many days. 0 = only for the current session.', 'surge-evaluation-popup' ),
			),
			'submitted_days'     => array(
				'tab'     => 'general',
				'type'    => 'number',
				'label'   => __( 'Hide after submit (days)', 'surge-evaluation-popup' ),
				'default' => 365,
				'min'     => 0,
			),
			'show_on_mobile'     => array(
				'tab'     => 'general',
				'type'    => 'checkbox',
				'label'   => __( 'Auto-open on mobile', 'surge-evaluation-popup' ),
				'default' => 1,
				'help'    => __( 'When off, the popup still opens from "surge-eval-open" buttons on mobile but never automatically.', 'surge-evaluation-popup' ),
			),

			// Content.
			'show_icon'          => array(
				'tab'     => 'content',
				'type'    => 'checkbox',
				'label'   => __( 'Show shield icon', 'surge-evaluation-popup' ),
				'default' => 1,
			),
			'badge_text'         => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Badge text', 'surge-evaluation-popup' ),
				'default' => '$99 Electrical Safety Evaluation',
			),
			'headline'           => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Headline', 'surge-evaluation-popup' ),
				'default' => 'Get Your Home’s Electrical System Professionally Evaluated',
			),
			'name_placeholder'   => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Name placeholder', 'surge-evaluation-popup' ),
				'default' => 'Your Name',
			),
			'phone_placeholder'  => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Phone placeholder', 'surge-evaluation-popup' ),
				'default' => 'Phone Number',
			),
			'zip_placeholder'    => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'ZIP placeholder', 'surge-evaluation-popup' ),
				'default' => 'ZIP Code',
			),
			'button_text'        => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Button text', 'surge-evaluation-popup' ),
				'default' => 'Schedule My $99 Evaluation',
			),
			'phone_number'       => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Call-us phone number', 'surge-evaluation-popup' ),
				'default' => '',
				'help'    => __( 'Used for the tel: link. Leave empty to hide the "Prefer to call?" line.', 'surge-evaluation-popup' ),
			),
			'call_text'          => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Call link text', 'surge-evaluation-popup' ),
				'default' => 'Prefer to call? Call Us to Schedule',
			),
			'show_rating'        => array(
				'tab'     => 'content',
				'type'    => 'checkbox',
				'label'   => __( 'Show star rating', 'surge-evaluation-popup' ),
				'default' => 1,
			),
			'rating_text'        => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Rating text', 'surge-evaluation-popup' ),
				'default' => '5-star rated by local homeowners',
			),
			'trust_text'         => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Trust line', 'surge-evaluation-popup' ),
				'default' => 'Licensed • Insured • Local',
			),
			'success_title'      => array(
				'tab'     => 'content',
				'type'    => 'text',
				'label'   => __( 'Success title', 'surge-evaluation-popup' ),
				'default' => 'You’re on the list!',
			),
			'success_message'    => array(
				'tab'     => 'content',
				'type'    => 'textarea',
				'label'   => __( 'Success message', 'surge-evaluation-popup' ),
				'default' => 'Thanks! A member of our team will call you shortly to schedule your $99 Electrical Safety Evaluation.',
			),

			// Location targeting.
			'geo_enabled'        => array(
				'tab'     => 'geo',
				'type'    => 'checkbox',
				'label'   => __( 'Only show inside the service area', 'surge-evaluation-popup' ),
				'default' => 1,
				'help'    => __( 'When off, the popup shows to every visitor regardless of location.', 'surge-evaluation-popup' ),
			),
			'geo_provider'       => array(
				'tab'     => 'geo',
				'type'    => 'select',
				'label'   => __( 'IP geolocation provider', 'surge-evaluation-popup' ),
				'default' => 'ipinfo',
				'options' => array(
					'ipinfo'     => __( 'ipinfo.io (token recommended, 50k/month free)', 'surge-evaluation-popup' ),
					'ipapi_co'   => __( 'ipapi.co (no key, ~1,000/day free)', 'surge-evaluation-popup' ),
					'ip_api'     => __( 'ip-api.com (no key, non-commercial free tier)', 'surge-evaluation-popup' ),
					'cloudflare' => __( 'Cloudflare visitor location headers (falls back to ipinfo)', 'surge-evaluation-popup' ),
				),
				'help'    => __( 'Lookups are cached per IP, so each visitor costs at most one API call per cache period. Cloudflare requires the "Add visitor location headers" Managed Transform.', 'surge-evaluation-popup' ),
			),
			'ipinfo_token'       => array(
				'tab'     => 'geo',
				'type'    => 'password',
				'label'   => __( 'ipinfo.io token', 'surge-evaluation-popup' ),
				'default' => '',
			),
			'center_lat'         => array(
				'tab'     => 'geo',
				'type'    => 'text',
				'label'   => __( 'Service area center latitude', 'surge-evaluation-popup' ),
				'default' => '47.6062',
				'help'    => __( 'Default is downtown Seattle.', 'surge-evaluation-popup' ),
			),
			'center_lng'         => array(
				'tab'     => 'geo',
				'type'    => 'text',
				'label'   => __( 'Service area center longitude', 'surge-evaluation-popup' ),
				'default' => '-122.3321',
			),
			'radius_miles'       => array(
				'tab'     => 'geo',
				'type'    => 'number',
				'label'   => __( 'Radius (miles)', 'surge-evaluation-popup' ),
				'default' => 50,
				'min'     => 1,
				'help'    => __( '50 miles from downtown Seattle covers the Seattle–Tacoma–Bellevue metro (Everett ~25 mi, Tacoma ~25 mi, Marysville ~35 mi, Puyallup ~30 mi, North Bend ~30 mi, Bremerton ~15 mi).', 'surge-evaluation-popup' ),
			),
			'restrict_state'     => array(
				'tab'     => 'geo',
				'type'    => 'checkbox',
				'label'   => __( 'Also require Washington State', 'surge-evaluation-popup' ),
				'default' => 1,
				'help'    => __( 'Prevents a large radius from spilling into British Columbia.', 'surge-evaluation-popup' ),
			),
			'allowed_cities'     => array(
				'tab'     => 'geo',
				'type'    => 'textarea',
				'label'   => __( 'Always-allowed cities', 'surge-evaluation-popup' ),
				'default' => '',
				'help'    => __( 'One per line. A visitor whose IP resolves to one of these cities is always eligible, even outside the radius.', 'surge-evaluation-popup' ),
			),
			'lookup_failure'     => array(
				'tab'     => 'geo',
				'type'    => 'select',
				'label'   => __( 'If the location cannot be determined', 'surge-evaluation-popup' ),
				'default' => 'hide',
				'options' => array(
					'hide' => __( 'Hide the popup', 'surge-evaluation-popup' ),
					'show' => __( 'Show the popup', 'surge-evaluation-popup' ),
				),
			),
			'trust_proxy'        => array(
				'tab'     => 'geo',
				'type'    => 'checkbox',
				'label'   => __( 'Trust proxy headers for visitor IP', 'surge-evaluation-popup' ),
				'default' => 0,
				'help'    => __( 'Enable if the site is behind Cloudflare or a load balancer (uses CF-Connecting-IP / X-Real-IP / X-Forwarded-For). Only enable when a proxy is actually in front of the site.', 'surge-evaluation-popup' ),
			),
			'geo_cache_hours'    => array(
				'tab'     => 'geo',
				'type'    => 'number',
				'label'   => __( 'Cache lookups (hours)', 'surge-evaluation-popup' ),
				'default' => 168,
				'min'     => 1,
			),

			// Mailgun.
			'mailgun_enabled'    => array(
				'tab'     => 'mailgun',
				'type'    => 'checkbox',
				'label'   => __( 'Send submissions by email', 'surge-evaluation-popup' ),
				'default' => 1,
			),
			'mailgun_api_key'    => array(
				'tab'     => 'mailgun',
				'type'    => 'password',
				'label'   => __( 'API key', 'surge-evaluation-popup' ),
				'default' => '',
				'help'    => __( 'A Mailgun sending key or private API key. Can also be set with define( \'SURGE_EVAL_MAILGUN_API_KEY\', \'...\' ) in wp-config.php.', 'surge-evaluation-popup' ),
			),
			'mailgun_domain'     => array(
				'tab'     => 'mailgun',
				'type'    => 'text',
				'label'   => __( 'Sending domain', 'surge-evaluation-popup' ),
				'default' => '',
				'help'    => __( 'e.g. mg.example.com', 'surge-evaluation-popup' ),
			),
			'mailgun_region'     => array(
				'tab'     => 'mailgun',
				'type'    => 'select',
				'label'   => __( 'Region', 'surge-evaluation-popup' ),
				'default' => 'us',
				'options' => array(
					'us' => 'US (api.mailgun.net)',
					'eu' => 'EU (api.eu.mailgun.net)',
				),
			),
			'mailgun_from_email' => array(
				'tab'     => 'mailgun',
				'type'    => 'email',
				'label'   => __( 'From email', 'surge-evaluation-popup' ),
				'default' => '',
			),
			'mailgun_from_name'  => array(
				'tab'     => 'mailgun',
				'type'    => 'text',
				'label'   => __( 'From name', 'surge-evaluation-popup' ),
				'default' => 'Surge Website',
			),
			'mailgun_to'         => array(
				'tab'     => 'mailgun',
				'type'    => 'text',
				'label'   => __( 'Recipients', 'surge-evaluation-popup' ),
				'default' => '',
				'help'    => __( 'Comma-separated list of email addresses.', 'surge-evaluation-popup' ),
			),
			'mailgun_subject'    => array(
				'tab'     => 'mailgun',
				'type'    => 'text',
				'label'   => __( 'Subject', 'surge-evaluation-popup' ),
				'default' => 'New $99 Evaluation Request: {name} ({zip})',
				'help'    => __( 'Placeholders: {name}, {phone}, {zip}, {city}', 'surge-evaluation-popup' ),
			),

			// Housecall Pro.
			'hcp_enabled'        => array(
				'tab'     => 'hcp',
				'type'    => 'checkbox',
				'label'   => __( 'Push submissions to Housecall Pro', 'surge-evaluation-popup' ),
				'default' => 1,
			),
			'hcp_api_key'        => array(
				'tab'     => 'hcp',
				'type'    => 'password',
				'label'   => __( 'API key', 'surge-evaluation-popup' ),
				'default' => '',
				'help'    => __( 'Leave empty to use the HCP_API_KEY constant from wp-config.php.', 'surge-evaluation-popup' ),
			),
			'hcp_reuse_customer' => array(
				'tab'     => 'hcp',
				'type'    => 'checkbox',
				'label'   => __( 'Reuse existing customer with the same phone', 'surge-evaluation-popup' ),
				'default' => 1,
				'help'    => __( 'Searches HCP by phone number first to avoid duplicate customers.', 'surge-evaluation-popup' ),
			),
			'hcp_create_lead'    => array(
				'tab'     => 'hcp',
				'type'    => 'checkbox',
				'label'   => __( 'Create a lead for the customer', 'surge-evaluation-popup' ),
				'default' => 1,
			),
			'hcp_lead_source'    => array(
				'tab'     => 'hcp',
				'type'    => 'text',
				'label'   => __( 'Lead source', 'surge-evaluation-popup' ),
				'default' => 'Website - $99 Evaluation Popup',
			),
			'hcp_tags'           => array(
				'tab'     => 'hcp',
				'type'    => 'text',
				'label'   => __( 'Tags', 'surge-evaluation-popup' ),
				'default' => 'Website Lead, $99 Evaluation',
				'help'    => __( 'Comma-separated. Applied to both the customer and the lead.', 'surge-evaluation-popup' ),
			),

			// Testing.
			'test_mode'          => array(
				'tab'     => 'testing',
				'type'    => 'select',
				'label'   => __( 'Test mode', 'surge-evaluation-popup' ),
				'default' => 'off',
				'options' => array(
					'off'      => __( 'Off (normal location targeting)', 'surge-evaluation-popup' ),
					'admins'   => __( 'Always show to logged-in administrators', 'surge-evaluation-popup' ),
					'everyone' => __( 'Always show to everyone (staging only!)', 'surge-evaluation-popup' ),
				),
			),
			'debug_log'          => array(
				'tab'     => 'testing',
				'type'    => 'checkbox',
				'label'   => __( 'Debug logging', 'surge-evaluation-popup' ),
				'default' => 0,
				'help'    => __( 'Writes geo lookups and API responses to the PHP error log (wp-content/debug.log when WP_DEBUG_LOG is on).', 'surge-evaluation-popup' ),
			),
		);
	}

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array_map(
			static function ( array $field ) {
				return $field['default'];
			},
			self::schema()
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitize and persist the fields belonging to one tab, keeping all others.
	 *
	 * @param string               $tab   Tab key.
	 * @param array<string, mixed> $input Raw submitted values.
	 */
	public static function save_tab( string $tab, array $input ): void {
		$values = self::all();

		foreach ( self::schema() as $key => $field ) {
			if ( $field['tab'] !== $tab ) {
				continue;
			}

			$raw = $input[ $key ] ?? null;

			switch ( $field['type'] ) {
				case 'checkbox':
					$values[ $key ] = empty( $raw ) ? 0 : 1;
					break;
				case 'number':
					$number = is_numeric( $raw ) ? (int) $raw : (int) $field['default'];
					if ( isset( $field['min'] ) ) {
						$number = max( (int) $field['min'], $number );
					}
					if ( isset( $field['max'] ) ) {
						$number = min( (int) $field['max'], $number );
					}
					$values[ $key ] = $number;
					break;
				case 'select':
					$raw            = is_string( $raw ) ? $raw : '';
					$values[ $key ] = array_key_exists( $raw, $field['options'] ) ? $raw : $field['default'];
					break;
				case 'password':
					// Blank means "keep the saved secret"; a lone "-" clears it.
					$raw = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';
					if ( '-' === $raw ) {
						$values[ $key ] = '';
					} elseif ( '' !== $raw ) {
						$values[ $key ] = sanitize_text_field( $raw );
					}
					break;
				case 'email':
					$values[ $key ] = sanitize_email( is_string( $raw ) ? wp_unslash( $raw ) : '' );
					break;
				case 'textarea':
					$values[ $key ] = sanitize_textarea_field( is_string( $raw ) ? wp_unslash( $raw ) : '' );
					break;
				default:
					$values[ $key ] = sanitize_text_field( is_string( $raw ) ? wp_unslash( $raw ) : '' );
			}
		}

		if ( 'geo' === $tab ) {
			foreach ( array( 'center_lat', 'center_lng' ) as $coordinate ) {
				if ( ! is_numeric( $values[ $coordinate ] ) ) {
					$values[ $coordinate ] = self::schema()[ $coordinate ]['default'];
				}
			}
		}

		update_option( self::OPTION, $values, false );
		self::$cache = null;
	}

	/**
	 * Override settings for the current request only (never persisted).
	 *
	 * @param array<string, mixed> $values Values.
	 */
	public static function override( array $values ): void {
		self::$cache = array_merge( self::all(), $values );
	}

	/**
	 * Housecall Pro API key (setting first, wp-config constant fallback).
	 */
	public static function hcp_api_key(): string {
		$key = (string) self::get( 'hcp_api_key' );

		if ( '' === $key && defined( 'HCP_API_KEY' ) ) {
			$key = (string) HCP_API_KEY;
		}

		return $key;
	}

	/**
	 * Mailgun API key (setting first, wp-config constant fallback).
	 */
	public static function mailgun_api_key(): string {
		$key = (string) self::get( 'mailgun_api_key' );

		if ( '' === $key && defined( 'SURGE_EVAL_MAILGUN_API_KEY' ) ) {
			$key = (string) SURGE_EVAL_MAILGUN_API_KEY;
		}

		return $key;
	}

	/**
	 * Split a comma-separated setting into a clean list.
	 *
	 * @param string $key Setting key.
	 * @return string[]
	 */
	public static function csv( string $key ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) self::get( $key ) ) ) ) );
	}
}
