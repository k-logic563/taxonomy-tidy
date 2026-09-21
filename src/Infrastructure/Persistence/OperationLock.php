<?php
/**
 * Taxonomy-scoped operation lock.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Infrastructure\Persistence;

use InvalidArgumentException;
use TaxonomyTidy\Infrastructure\Database\Tables;
use wpdb;

/**
 * Prevents two operations from holding a mutation lease for one taxonomy.
 */
final class OperationLock {
	/**
	 * WordPress database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Operation table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Creates the lock service for a site database.
	 *
	 * @param \wpdb $database WordPress database connection.
	 */
	public function __construct( wpdb $database ) {
		$this->database = $database;
		$this->table    = Tables::operations( $database );
	}

	/**
	 * Acquires a taxonomy-scoped lease or returns null when it is held elsewhere.
	 *
	 * @param int $operation_id Operation that will own the lease.
	 * @param int $ttl_seconds  Lease lifetime in seconds.
	 * @throws \InvalidArgumentException When the lease lifetime is not positive.
	 */
	public function acquire( int $operation_id, int $ttl_seconds = 60 ): ?string {
		if ( $ttl_seconds < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new InvalidArgumentException( 'The lock lifetime must be positive.' );
		}

		$taxonomy = $this->operation_taxonomy( $operation_id );

		if ( null === $taxonomy ) {
			return null;
		}

		return $this->acquire_named( $operation_id, $taxonomy, $ttl_seconds );
	}

	/**
	 * Acquires the short critical-section lease shared by preview and Undo start.
	 *
	 * The original operation ID scopes this lease, so previews for unrelated
	 * originals do not block one another. The existing unique lock_name column
	 * supplies the atomic database guarantee without a schema change.
	 *
	 * @param int $original_operation_id Original operation that is being undone.
	 * @param int $ttl_seconds            Lease lifetime in seconds.
	 * @throws \InvalidArgumentException When the lease lifetime is not positive.
	 */
	public function acquire_undo_parent( int $original_operation_id, int $ttl_seconds = 60 ): ?string {
		return $this->acquire_named( $original_operation_id, 'undo:' . $original_operation_id, $ttl_seconds );
	}

	/**
	 * Acquires one atomic named lease on an existing operation row.
	 *
	 * @param int    $operation_id Row that owns the lease.
	 * @param string $lock_name    Unique logical resource name.
	 * @param int    $ttl_seconds  Lease lifetime in seconds.
	 * @throws \InvalidArgumentException When the lease lifetime is not positive.
	 */
	private function acquire_named( int $operation_id, string $lock_name, int $ttl_seconds ): ?string {
		if ( $ttl_seconds < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new InvalidArgumentException( 'The lock lifetime must be positive.' );
		}
		if ( null === $this->operation_taxonomy( $operation_id ) ) {
			return null;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic custom-table lock cannot use a core cache API.
		$this->database->query(
			$this->database->prepare(
				'UPDATE %i
				SET lock_name = NULL, lock_token = NULL, lock_expires_at = NULL, updated_at = %s
				WHERE (id = %d OR lock_name = %s) AND lock_expires_at <= %s',
				$this->table,
				$now,
				$operation_id,
				$lock_name,
				$now
			)
		);

		$token      = str_replace( '-', '', wp_generate_uuid4() );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic custom-table lock cannot use a core cache API.
		$result = $this->database->query(
			$this->database->prepare(
				'UPDATE IGNORE %i
				SET lock_name = %s, lock_token = %s, lock_expires_at = %s, updated_at = %s
				WHERE id = %d AND lock_name IS NULL',
				$this->table,
				$lock_name,
				$token,
				$expires_at,
				$now,
				$operation_id
			)
		);

		return 1 === $result ? $token : null;
	}

	/**
	 * Checks that a supplied reservation is still owned and extends its lease.
	 *
	 * @param int    $operation_id Operation that owns the reservation.
	 * @param string $token        Reservation token.
	 * @param int    $ttl_seconds  New lease lifetime in seconds.
	 */
	public function owns( int $operation_id, string $token, int $ttl_seconds = 60 ): bool {
		return $this->renew( $operation_id, $token, $ttl_seconds );
	}

	/**
	 * Extends a lease held by the supplied token.
	 *
	 * @param int    $operation_id Operation that owns the lease.
	 * @param string $token        Lease ownership token.
	 * @param int    $ttl_seconds  New lease lifetime in seconds.
	 */
	public function renew( int $operation_id, string $token, int $ttl_seconds = 60 ): bool {
		if ( $ttl_seconds < 1 ) {
			return false;
		}

		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic custom-table lock cannot use a core cache API.
		$result = $this->database->query(
			$this->database->prepare(
				'UPDATE %i SET lock_expires_at = %s, updated_at = %s
				WHERE id = %d AND lock_token = %s AND lock_expires_at > %s',
				$this->table,
				$expires_at,
				$now,
				$operation_id,
				$token,
				$now
			)
		);

		if ( 1 === $result ) {
			return true;
		}
		if ( false === $result ) {
			return false;
		}

		// A renewal within the same second can be a valid no-op update. Recheck
		// ownership and expiry rather than treating MySQL's zero changed rows as loss.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic custom-table lock cannot use a core cache API.
		$owned = $this->database->get_var(
			$this->database->prepare(
				'SELECT id FROM %i WHERE id = %d AND lock_token = %s AND lock_expires_at > %s',
				$this->table,
				$operation_id,
				$token,
				$now
			)
		);

		return null !== $owned;
	}

	/**
	 * Releases a lease only when the caller still owns its token.
	 *
	 * @param int    $operation_id Operation that owns the lease.
	 * @param string $token        Lease ownership token.
	 */
	public function release( int $operation_id, string $token ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic custom-table lock cannot use a core cache API.
		$result = $this->database->query(
			$this->database->prepare(
				'UPDATE %i
				SET lock_name = NULL, lock_token = NULL, lock_expires_at = NULL, updated_at = %s
				WHERE id = %d AND lock_token = %s',
				$this->table,
				current_time( 'mysql', true ),
				$operation_id,
				$token
			)
		);

		return 1 === $result;
	}

	/**
	 * Returns the taxonomy of an existing operation.
	 *
	 * @param int $operation_id Operation whose taxonomy is requested.
	 */
	private function operation_taxonomy( int $operation_id ): ?string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$taxonomy = $this->database->get_var(
			$this->database->prepare(
				'SELECT taxonomy FROM %i WHERE id = %d',
				$this->table,
				$operation_id
			)
		);

		return is_string( $taxonomy ) ? $taxonomy : null;
	}
}
