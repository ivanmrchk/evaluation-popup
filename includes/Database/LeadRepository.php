<?php
/**
 * Submission storage.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes popup submissions.
 */
final class LeadRepository {
	/**
	 * Insert a lead.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int Inserted ID, 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( Installer::table(), $data );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a lead.
	 *
	 * @param int                  $id   Lead ID.
	 * @param array<string, mixed> $data Column values.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Installer::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * Find a lead.
	 *
	 * @param int $id Lead ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Installer::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Most recent leads.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table = Installer::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count all leads.
	 */
	public function count(): int {
		global $wpdb;

		$table = Installer::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete a lead.
	 *
	 * @param int $id Lead ID.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Installer::table(), array( 'id' => $id ) );
	}
}
