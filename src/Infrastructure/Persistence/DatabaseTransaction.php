<?php
/**
 * Small transaction boundary for one execution item.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Infrastructure\Persistence;

use wpdb;

/** Keeps a WordPress mutation, journal entry, and item status atomic. */
final class DatabaseTransaction {
	/**
	 * Creates the transaction boundary.
	 *
	 * @param \wpdb $database WordPress database connection.
	 */
	public function __construct( private readonly wpdb $database ) {
	}

	/** Starts a transaction. */
	public function begin(): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Fixed transaction control statement.
		return false !== $this->database->query( 'START TRANSACTION' );
	}

	/** Commits a transaction. */
	public function commit(): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Fixed transaction control statement.
		return false !== $this->database->query( 'COMMIT' );
	}

	/** Rolls back a transaction. */
	public function rollback(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Fixed transaction control statement.
		$this->database->query( 'ROLLBACK' );
	}
}
