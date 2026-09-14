<?php
/**
 * Operation item persistence.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Infrastructure\Persistence;

use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\ItemStatus;
use TaxonomyTidy\Infrastructure\Database\Tables;
use wpdb;

/**
 * Stores fixed units of work and exposes pending items for safe retries.
 */
final class OperationItemRepository {
	/**
	 * WordPress database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Operation item table name.
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
		$this->table    = Tables::items( $database );
	}

	/**
	 * Adds a uniquely keyed item to an operation.
	 *
	 * @param int                  $operation_id Parent operation ID.
	 * @param string               $item_key     Stable idempotency key.
	 * @param Action               $action       MVP action type.
	 * @param array<string, mixed> $payload      Action and undo metadata.
	 * @throws PersistenceException When payload encoding or database insertion fails.
	 */
	public function add( int $operation_id, string $item_key, Action $action, array $payload ): int {
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom item table has no core API or object cache.
		$result = $this->database->insert(
			$this->table,
			array(
				'operation_id' => $operation_id,
				'item_key'     => $item_key,
				'action'       => $action->value,
				'status'       => ItemStatus::PENDING->value,
				'payload'      => Json::encode( $payload ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new PersistenceException( 'The operation item could not be created.' );
		}

		return (int) $this->database->insert_id;
	}

	/**
	 * Returns pending items in stable keyset order.
	 *
	 * An item remains pending while an attempt is in progress. If a request is
	 * interrupted, the same item is therefore discoverable and can be retried by
	 * the idempotent executor implemented in Phase 5.
	 *
	 * @param int $operation_id Parent operation ID.
	 * @param int $limit        Maximum number of pending items to return.
	 * @return list<array<string, mixed>>
	 * @throws \JsonException       When a stored payload is invalid JSON.
	 * @throws PersistenceException When a stored payload is not an array.
	 */
	public function find_pending( int $operation_id, int $limit ): array {
		if ( $limit < 1 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom item table has no core API or object cache.
		$rows = $this->database->get_results(
			$this->database->prepare(
				'SELECT * FROM %i WHERE operation_id = %d AND status = %s ORDER BY id ASC LIMIT %d',
				$this->table,
				$operation_id,
				ItemStatus::PENDING->value,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns every item for history and undo planning in stable order.
	 *
	 * @param int $operation_id Parent operation ID.
	 * @return list<array<string, mixed>>
	 * @throws \JsonException       When a stored payload is invalid JSON.
	 * @throws PersistenceException When a stored payload is not an array.
	 */
	public function find_for_operation( int $operation_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom item table has no core API or object cache.
		$rows = $this->database->get_results(
			$this->database->prepare(
				'SELECT * FROM %i WHERE operation_id = %d ORDER BY id ASC',
				$this->table,
				$operation_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Records an execution attempt without hiding the item from retry discovery.
	 *
	 * @param int $item_id Operation item ID.
	 */
	public function record_attempt( int $item_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom item table has no core API or object cache.
		$result = $this->database->query(
			$this->database->prepare(
				'UPDATE %i SET attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = %s',
				$this->table,
				current_time( 'mysql', true ),
				$item_id,
				ItemStatus::PENDING->value
			)
		);

		return 1 === $result;
	}

	/**
	 * Marks a pending item completed.
	 *
	 * @param int $item_id Operation item ID.
	 */
	public function mark_completed( int $item_id ): bool {
		return $this->set_terminal_status( $item_id, ItemStatus::COMPLETED, null );
	}

	/**
	 * Marks a pending item failed with its latest error.
	 *
	 * @param int    $item_id Operation item ID.
	 * @param string $error   Latest execution error.
	 */
	public function mark_failed( int $item_id, string $error ): bool {
		return $this->set_terminal_status( $item_id, ItemStatus::FAILED, $error );
	}

	/**
	 * Normalizes a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 * @throws \JsonException       When the stored payload is invalid JSON.
	 * @throws PersistenceException When the stored payload is not an array.
	 */
	private function normalize_row( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['operation_id'] = (int) $row['operation_id'];
		$row['attempts']     = (int) $row['attempts'];
		$row['payload']      = Json::decode( (string) $row['payload'] );

		return $row;
	}

	/**
	 * Applies a terminal item state only while the item remains pending.
	 *
	 * @param int         $item_id Operation item ID.
	 * @param ItemStatus  $status  Terminal status to persist.
	 * @param string|null $error   Latest error, or null for successful completion.
	 */
	private function set_terminal_status( int $item_id, ItemStatus $status, ?string $error ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom item table has no core API or object cache.
		$result = $this->database->update(
			$this->table,
			array(
				'status'     => $status->value,
				'last_error' => $error,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $item_id,
				'status' => ItemStatus::PENDING->value,
			)
		);

		return 1 === $result;
	}
}
