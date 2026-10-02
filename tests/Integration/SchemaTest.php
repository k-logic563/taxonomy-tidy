<?php
/**
 * Database schema integration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use TermSteward\Infrastructure\Database\Schema;
use TermSteward\Infrastructure\Database\Tables;
use TermSteward\Lifecycle;
use WP_UnitTestCase;

/**
 * Verifies fresh and repeated schema installation.
 */
final class SchemaTest extends WP_UnitTestCase {
	/**
	 * Term Steward uses independent persistence and leaves legacy-looking data untouched.
	 */
	public function test_activation_does_not_touch_old_plugin_persistence(): void {
		global $wpdb;

		$legacy_table  = $wpdb->prefix . 'taxonomy_tidy_operations';
		$legacy_option = 'taxonomy_tidy_schema_version';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated compatibility fixture for another plugin's table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $legacy_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated compatibility fixture for another plugin's table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id bigint(20) unsigned NOT NULL)', $legacy_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated compatibility fixture for another plugin's table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (id) VALUES (%d)', $legacy_table, 563 ) );
		update_option( $legacy_option, 'owned-by-another-plugin', false );

		try {
			Lifecycle::activate();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verifies the isolated compatibility fixture was not touched.
			$this->assertSame( '563', $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i', $legacy_table ) ) );
			$this->assertSame( 'owned-by-another-plugin', get_option( $legacy_option ) );
			$this->assertSame( Schema::VERSION, get_option( 'term_steward_schema_version' ) );
			$this->assertSame( $wpdb->prefix . 'term_steward_operations', Tables::operations( $wpdb ) );
			$this->assertSame( $wpdb->prefix . 'term_steward_operation_items', Tables::items( $wpdb ) );
			$this->assertSame( $wpdb->prefix . 'term_steward_changes', Tables::changes( $wpdb ) );
		} finally {
			delete_option( $legacy_option );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes only the isolated compatibility fixture.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $legacy_table ) );
		}
	}

	/**
	 * Activation creates all tables and can safely run more than once.
	 */
	public function test_install_is_fresh_and_idempotent(): void {
		global $wpdb;

		$tables = array(
			Tables::changes( $wpdb ),
			Tables::items( $wpdb ),
			Tables::operations( $wpdb ),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- This resets only the isolated test schema.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_option( 'term_steward_schema_version' );

		Lifecycle::activate();
		Lifecycle::activate();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema assertion against an isolated test database.
			$actual = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
			);
			$this->assertSame( $table, $actual );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema assertion against an isolated test database.
		$operation_columns = $wpdb->get_col(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::operations( $wpdb ) )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema assertion against an isolated test database.
		$item_columns = $wpdb->get_col(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::items( $wpdb ) )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema assertion against an isolated test database.
		$change_columns = $wpdb->get_col(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::changes( $wpdb ) )
		);

		$this->assertContains( 'plan_hash', $operation_columns );
		$this->assertContains( 'lock_token', $operation_columns );
		$this->assertContains( 'attempts', $item_columns );
		$this->assertContains( 'before_data', $change_columns );
		$this->assertContains( 'undone_at', $change_columns );

		$this->assertSame( Schema::VERSION, Schema::stored_version() );
	}
}
