<?php
/**
 * Database installer.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the submissions table.
 */
final class Installer {
	/**
	 * Schema version.
	 */
	private const DB_VERSION = '1';

	/**
	 * Option storing the installed schema version.
	 */
	private const DB_VERSION_OPTION = 'surge_eval_popup_db_version';

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Install when the stored schema version is outdated.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Submissions table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'surge_eval_leads';
	}

	/**
	 * Create or update the table.
	 */
	private static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				name varchar(191) NOT NULL DEFAULT '',
				phone varchar(32) NOT NULL DEFAULT '',
				zip varchar(10) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				geo_city varchar(100) NOT NULL DEFAULT '',
				geo_region varchar(100) NOT NULL DEFAULT '',
				page_url text NULL,
				referrer text NULL,
				is_test tinyint(1) NOT NULL DEFAULT 0,
				mailgun_status varchar(20) NOT NULL DEFAULT 'pending',
				mailgun_message text NULL,
				hcp_status varchar(20) NOT NULL DEFAULT 'pending',
				hcp_customer_id varchar(64) NOT NULL DEFAULT '',
				hcp_lead_id varchar(64) NOT NULL DEFAULT '',
				hcp_message text NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY phone (phone)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
