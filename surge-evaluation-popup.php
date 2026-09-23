<?php
/**
 * Plugin Name: Surge Evaluation Popup
 * Description: Location-aware $99 Electrical Safety Evaluation popup (shortcode) for the Seattle metro area. Sends leads via Mailgun and pushes them to Housecall Pro.
 * Version: 0.1.1
 * Author: Surge Electrical
 * Text Domain: surge-evaluation-popup
 * Requires PHP: 7.4
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SURGE_EVAL_POPUP_VERSION', '0.1.1' );
define( 'SURGE_EVAL_POPUP_FILE', __FILE__ );
define( 'SURGE_EVAL_POPUP_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'SurgeEvaluationPopup\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file           = SURGE_EVAL_POPUP_PATH . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		SurgeEvaluationPopup\Database\Installer::activate();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		SurgeEvaluationPopup\Plugin::instance()->init();
	}
);
