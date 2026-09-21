<?php
/**
 * Operation persistence.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Infrastructure\Persistence;

use TaxonomyTidy\Domain\Operation\InvalidStatusTransition;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\StatusTransitions;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Database\Tables;
use wpdb;

/**
 * Stores operation metadata and applies atomic state transitions.
 */
final class OperationRepository {
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
	 * Creates the repository for a site database.
	 *
	 * @param \wpdb $database WordPress database connection.
	 */
	public function __construct( wpdb $database ) {
		$this->database = $database;
		$this->table    = Tables::operations( $database );
	}

	/**
	 * Creates a draft operation.
	 *
	 * @param int                  $user_id             Administrator user ID.
	 * @param Taxonomy             $taxonomy            Supported taxonomy.
	 * @param array<string, mixed> $requested_data      Requested operation data.
	 * @param int|null             $parent_operation_id Original operation for an undo record.
	 * @throws PersistenceException When JSON encoding or database insertion fails.
	 */
	public function create(
		int $user_id,
		Taxonomy $taxonomy,
		array $requested_data = array(),
		?int $parent_operation_id = null
	): int {
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$result = $this->database->insert(
			$this->table,
			array(
				'parent_operation_id' => $parent_operation_id,
				'user_id'             => $user_id,
				'taxonomy'            => $taxonomy->value,
				'status'              => Status::DRAFT->value,
				'requested_data'      => Json::encode( $requested_data ),
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new PersistenceException( 'The operation could not be created.' );
		}

		return (int) $this->database->insert_id;
	}

	/**
	 * Returns a stored operation or null when it does not exist.
	 *
	 * @param int $operation_id Operation ID to retrieve.
	 * @return array<string, mixed>|null
	 * @throws \JsonException       When a stored JSON field is invalid.
	 * @throws PersistenceException When a stored JSON field is not an array.
	 */
	public function find( int $operation_id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$row = $this->database->get_row(
			$this->database->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table,
				$operation_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['id']                  = (int) $row['id'];
		$row['parent_operation_id'] = null === $row['parent_operation_id']
			? null
			: (int) $row['parent_operation_id'];
		$row['user_id']             = (int) $row['user_id'];
		$row['requested_data']      = Json::decode( (string) $row['requested_data'] );
		$row['result_data']         = $this->decode_nullable_json( $row['result_data'] );
		$row['errors']              = $this->decode_nullable_json( $row['errors'] );
		$row['warnings']            = $this->decode_nullable_json( $row['warnings'] );

		return $row;
	}

	/**
	 * Returns one operation only when it belongs to the current administrator.
	 *
	 * @param int $operation_id Operation ID.
	 * @param int $user_id      Administrator ID.
	 * @return array<string, mixed>|null
	 */
	public function find_owned( int $operation_id, int $user_id ): ?array {
		$operation = $this->find( $operation_id );
		return null !== $operation && $user_id === (int) $operation['user_id'] ? $operation : null;
	}

	/**
	 * Returns started operations newest first for the owner-facing history.
	 *
	 * @param int $user_id Administrator ID.
	 * @param int $page    One-based page.
	 * @param int $per_page Page size.
	 * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function history( int $user_id, int $page, int $per_page ): array {
		$per_page = max( 1, min( 100, $per_page ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom audit table has no core API or object cache.
		$total       = (int) $this->database->get_var(
			$this->database->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND started_at IS NOT NULL', $this->table, $user_id )
		);
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = max( 1, min( $page, $total_pages ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom audit table has no core API or object cache.
		$ids        = $this->database->get_col(
			$this->database->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND started_at IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT %d OFFSET %d',
				$this->table,
				$user_id,
				$per_page,
				( $page - 1 ) * $per_page
			)
		);
		$operations = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$operation = $this->find( (int) $id );
			if ( null !== $operation ) {
				$operations[] = $operation;
			}
		}
		return array(
			'items'       => $operations,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Returns started Undo records for an original operation.
	 *
	 * @param int $parent_operation_id Original operation ID.
	 * @return list<array<string, mixed>>
	 */
	public function started_undos( int $parent_operation_id ): array {
		return array_values(
			array_filter(
				$this->undos( $parent_operation_id ),
				static fn( array $operation ): bool => null !== $operation['started_at']
			)
		);
	}

	/**
	 * Returns every Undo child for an original operation, including previews.
	 *
	 * Existing duplicate rows are reported to callers and are never deleted.
	 *
	 * @param int $parent_operation_id Original operation ID.
	 * @return list<array<string, mixed>>
	 */
	public function undos( int $parent_operation_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom audit table has no core API or object cache.
		$ids        = $this->database->get_col(
			$this->database->prepare(
				'SELECT id FROM %i WHERE parent_operation_id = %d ORDER BY id DESC',
				$this->table,
				$parent_operation_id
			)
		);
		$operations = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$operation = $this->find( (int) $id );
			if ( null !== $operation ) {
				$operations[] = $operation;
			}
		}
		return $operations;
	}

	/**
	 * Returns the latest editable draft or preview for one user and taxonomy.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Supported taxonomy.
	 * @return array<string, mixed>|null
	 * @throws \JsonException       When a stored JSON field is invalid.
	 * @throws PersistenceException When a stored JSON field is not an array.
	 */
	public function find_editable( int $user_id, Taxonomy $taxonomy ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$operation_id = $this->database->get_var(
			$this->database->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND taxonomy = %s AND status IN (%s, %s) ORDER BY id DESC LIMIT 1',
				$this->table,
				$user_id,
				$taxonomy->value,
				Status::DRAFT->value,
				Status::PREVIEWED->value
			)
		);

		return null === $operation_id ? null : $this->find( (int) $operation_id );
	}

	/**
	 * Returns the latest draft for one administrator and taxonomy.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Supported taxonomy.
	 * @return array<string, mixed>|null
	 */
	public function find_draft( int $user_id, Taxonomy $taxonomy ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$operation_id = $this->database->get_var(
			$this->database->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND taxonomy = %s AND status = %s ORDER BY id DESC LIMIT 1',
				$this->table,
				$user_id,
				$taxonomy->value,
				Status::DRAFT->value
			)
		);
		return null === $operation_id ? null : $this->find( (int) $operation_id );
	}

	/**
	 * Returns the latest interrupted running record for one owner and taxonomy.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Supported taxonomy.
	 * @return array<string, mixed>|null
	 */
	public function find_running( int $user_id, Taxonomy $taxonomy ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$operation_id = $this->database->get_var(
			$this->database->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND taxonomy = %s AND status = %s ORDER BY id DESC LIMIT 1',
				$this->table,
				$user_id,
				$taxonomy->value,
				Status::RUNNING->value
			)
		);
		return null === $operation_id ? null : $this->find( (int) $operation_id );
	}

	/**
	 * Saves an owned plan as draft and invalidates any previous preview.
	 *
	 * @param int                  $operation_id Operation ID.
	 * @param int                  $user_id      Owning administrator user ID.
	 * @param array<string, mixed> $requested_data Sanitized draft data.
	 * @throws PersistenceException When JSON encoding or database update fails.
	 */
	public function save_draft( int $operation_id, int $user_id, array $requested_data ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$result = $this->database->query(
			$this->database->prepare(
				'UPDATE %i SET status = %s, plan_hash = NULL, state_fingerprint = NULL, requested_data = %s, result_data = NULL, errors = NULL, warnings = NULL, updated_at = %s WHERE id = %d AND user_id = %d AND status IN (%s, %s)',
				$this->table,
				Status::DRAFT->value,
				Json::encode( $requested_data ),
				current_time( 'mysql', true ),
				$operation_id,
				$user_id,
				Status::DRAFT->value,
				Status::PREVIEWED->value
			)
		);

		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal persistence exception; not HTML output.
			throw new PersistenceException( 'The draft operation could not be saved.' );
		}

		if ( 0 === $result ) {
			// A no-op update is valid only when this editable operation still exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
			$exists = $this->database->get_var(
				$this->database->prepare(
					'SELECT id FROM %i WHERE id = %d AND user_id = %d AND status IN (%s, %s)',
					$this->table,
					$operation_id,
					$user_id,
					Status::DRAFT->value,
					Status::PREVIEWED->value
				)
			);
			if ( null === $exists ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal persistence exception; not HTML output.
				throw new PersistenceException( 'The draft operation is not editable.' );
			}
		}
	}

	/**
	 * Deletes an owned draft or preview and its operation items.
	 *
	 * @param int $operation_id Operation ID.
	 * @param int $user_id      Owning administrator user ID.
	 * @throws PersistenceException When the operation cannot be discarded.
	 */
	public function discard( int $operation_id, int $user_id ): void {
		$items_table = Tables::items( $this->database );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$owned_operation = $this->database->get_var(
			$this->database->prepare(
				'SELECT id FROM %i WHERE id = %d AND user_id = %d AND status IN (%s, %s)',
				$this->table,
				$operation_id,
				$user_id,
				Status::DRAFT->value,
				Status::PREVIEWED->value
			)
		);
		if ( null === $owned_operation ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal persistence exception; not HTML output.
			throw new PersistenceException( 'The operation could not be discarded.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation item table has no core API or object cache.
		$items_deleted = $this->database->delete( $items_table, array( 'operation_id' => $operation_id ), array( '%d' ) );
		if ( false === $items_deleted ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal persistence exception; not HTML output.
			throw new PersistenceException( 'The operation items could not be discarded.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$result = $this->database->query(
			$this->database->prepare(
				'DELETE FROM %i WHERE id = %d AND user_id = %d AND status IN (%s, %s)',
				$this->table,
				$operation_id,
				$user_id,
				Status::DRAFT->value,
				Status::PREVIEWED->value
			)
		);

		if ( 1 !== $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal persistence exception; not HTML output.
			throw new PersistenceException( 'The operation could not be discarded.' );
		}
	}

	/**
	 * Stores the normalized preview inputs without changing operation state.
	 *
	 * @param int                  $operation_id     Operation ID.
	 * @param string               $plan_hash        Normalized plan hash.
	 * @param string               $state_fingerprint Relevant taxonomy-state fingerprint.
	 * @param array<string, mixed> $requested_data   Normalized requested data.
	 * @throws PersistenceException When requested data cannot be encoded.
	 */
	public function save_preview_context(
		int $operation_id,
		string $plan_hash,
		string $state_fingerprint,
		array $requested_data
	): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$result = $this->database->update(
			$this->table,
			array(
				'plan_hash'         => $plan_hash,
				'state_fingerprint' => $state_fingerprint,
				'requested_data'    => Json::encode( $requested_data ),
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $operation_id )
		);

		return false !== $result;
	}

	/**
	 * Stores truthful execution or undo summary data.
	 *
	 * @param int                  $operation_id Operation ID.
	 * @param array<string, mixed> $result_data  Actual result summary.
	 * @param array<string, mixed> $errors       Errors keyed for later reporting.
	 * @param array<string, mixed> $warnings     Warnings keyed for later reporting.
	 * @throws PersistenceException When result data cannot be encoded.
	 */
	public function save_result(
		int $operation_id,
		array $result_data,
		array $errors = array(),
		array $warnings = array()
	): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$result = $this->database->update(
			$this->table,
			array(
				'result_data' => Json::encode( $result_data ),
				'errors'      => Json::encode( $errors ),
				'warnings'    => Json::encode( $warnings ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $operation_id )
		);

		return false !== $result;
	}

	/**
	 * Applies a validated, compare-and-set status transition.
	 *
	 * InvalidStatusTransition, \JsonException, and \ValueError may propagate from
	 * transition validation and stored-operation decoding.
	 *
	 * @param int    $operation_id Operation ID to transition.
	 * @param Status $to           Requested next status.
	 * @throws PersistenceException When the operation is missing or cannot be updated.
	 */
	public function transition( int $operation_id, Status $to ): void {
		$operation = $this->find( $operation_id );

		if ( null === $operation ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new PersistenceException( 'The operation does not exist.' );
		}

		$from = Status::from( (string) $operation['status'] );
		StatusTransitions::assert_allowed( $from, $to );

		$now  = current_time( 'mysql', true );
		$data = array(
			'status'     => $to->value,
			'updated_at' => $now,
		);

		if ( in_array( $to, array( Status::RUNNING, Status::UNDOING ), true ) ) {
			$data['started_at'] = $now;
		}

		if ( in_array( $to, self::terminal_statuses(), true ) ) {
			$data['completed_at'] = $now;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom operation table has no core API or object cache.
		$result = $this->database->update(
			$this->table,
			$data,
			array(
				'id'     => $operation_id,
				'status' => $from->value,
			)
		);

		if ( 1 !== $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new PersistenceException( 'The operation status changed concurrently or could not be saved.' );
		}
	}

	/**
	 * Returns statuses that finish an execution or undo attempt.
	 *
	 * @return list<Status>
	 */
	private static function terminal_statuses(): array {
		return array(
			Status::COMPLETED,
			Status::PARTIAL_FAILED,
			Status::FAILED,
			Status::UNDONE,
			Status::UNDO_PARTIAL_FAILED,
		);
	}

	/**
	 * Decodes a nullable JSON database field.
	 *
	 * @param mixed $value Nullable database field value.
	 * @return array<string, mixed>|null
	 * @throws \JsonException       When the stored value is invalid JSON.
	 * @throws PersistenceException When the stored value is not an array.
	 */
	private function decode_nullable_json( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}

		return Json::decode( (string) $value );
	}
}
