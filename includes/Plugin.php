<?php
/**
 * Main plugin coordinator.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup;

use SurgeEvaluationPopup\Admin\SettingsPage;
use SurgeEvaluationPopup\Database\Installer;
use SurgeEvaluationPopup\Frontend\Shortcode;
use SurgeEvaluationPopup\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires plugin services into WordPress.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get the plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function init(): void {
		( new Shortcode() )->register_hooks();
		( new Controller() )->register_hooks();

		if ( is_admin() ) {
			add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
			( new SettingsPage() )->register_hooks();
		}
	}

	/**
	 * Write a debug line to the PHP error log when debug logging is enabled.
	 *
	 * @param string              $message Message.
	 * @param array<mixed>|string $context Extra context.
	 */
	public static function log( string $message, $context = array() ): void {
		if ( ! Settings::get( 'debug_log' ) ) {
			return;
		}

		$suffix = empty( $context ) ? '' : ' ' . ( is_string( $context ) ? $context : wp_json_encode( $context ) );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[Surge Evaluation Popup] ' . $message . $suffix );
	}

	/**
	 * Keep construction internal.
	 */
	private function __construct() {}
}
