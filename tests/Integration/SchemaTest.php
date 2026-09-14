<?php
/**
 * Database schema integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Infrastructure\Database\Schema;
use TaxonomyTidy\Infrastructure\Database\Tables;
use TaxonomyTidy\Lifecycle;
use WP_UnitTestCase;

/**
 * Verifies fresh and repeated schema installation.
 */
final class SchemaTest extends WP_UnitTestCase {
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
		delete_option( 'taxonomy_tidy_schema_version' );

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
