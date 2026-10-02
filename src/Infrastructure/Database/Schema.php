<?php
/**
 * Plugin database schema.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Infrastructure\Database;

use wpdb;

/**
 * Installs and versions the three Phase 2 persistence tables.
 */
final class Schema {
	/**
	 * Current schema version.
	 *
	 * @var string
	 */
	public const VERSION = '1';

	/**
	 * WordPress option containing the installed schema version.
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'term_steward_schema_version';

	/**
	 * Creates or updates the schema idempotently with WordPress dbDelta().
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::statements( $wpdb ) as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Applies a schema update only when the stored version is outdated.
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Returns the stored schema version.
	 */
	public static function stored_version(): ?string {
		$version = get_option( self::VERSION_OPTION, null );

		return is_string( $version ) ? $version : null;
	}

	/**
	 * Returns dbDelta-compatible CREATE TABLE statements.
	 *
	 * @param \wpdb $database WordPress database connection supplying charset and table-prefix data.
	 * @return list<string>
	 */
	private static function statements( wpdb $database ): array {
		$operations_table = Tables::operations( $database );
		$items_table      = Tables::items( $database );
		$changes_table    = Tables::changes( $database );
		$charset_collate  = $database->get_charset_collate();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the trusted WP prefix.
		$operations = "CREATE TABLE {$operations_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_operation_id bigint(20) unsigned DEFAULT NULL,
			user_id bigint(20) unsigned NOT NULL,
			taxonomy varchar(32) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'draft',
			plan_hash char(64) DEFAULT NULL,
			state_fingerprint char(64) DEFAULT NULL,
			requested_data longtext NOT NULL,
			result_data longtext NULL,
			errors longtext NULL,
			warnings longtext NULL,
			lock_name varchar(32) DEFAULT NULL,
			lock_token varchar(64) DEFAULT NULL,
			lock_expires_at datetime DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY active_lock (lock_name),
			KEY parent_operation_id (parent_operation_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the trusted WP prefix.
		$items = "CREATE TABLE {$items_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_id bigint(20) unsigned NOT NULL,
			item_key varchar(191) NOT NULL,
			action varchar(16) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			payload longtext NOT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_item (operation_id,item_key),
			KEY pending_items (operation_id,status,id)
		) {$charset_collate};";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the trusted WP prefix.
		$changes = "CREATE TABLE {$changes_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned NOT NULL,
			change_key varchar(191) NOT NULL,
			object_id bigint(20) unsigned DEFAULT NULL,
			change_type varchar(32) NOT NULL,
			before_data longtext NULL,
			after_data longtext NULL,
			created_at datetime NOT NULL,
			undone_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_change (operation_id,change_key),
			KEY item_id (item_id),
			KEY object_id (object_id)
		) {$charset_collate};";
		return array( $operations, $items, $changes );
	}
}
