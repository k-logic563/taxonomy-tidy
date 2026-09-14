<?php
/**
 * Change journal persistence.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Infrastructure\Persistence;

use TaxonomyTidy\Infrastructure\Database\Tables;
use wpdb;

/**
 * Stores actual changes once by a stable per-operation key.
 */
final class ChangeJournalRepository {
	/**
	 * WordPress database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Change journal table name.
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
		$this->table    = Tables::changes( $database );
	}

	/**
	 * Records a change idempotently and returns its stable row ID.
	 *
	 * @param int                       $operation_id Operation ID.
	 * @param int                       $item_id      Operation item ID.
	 * @param string                    $change_key   Stable idempotency key.
	 * @param string                    $change_type  Actual change type.
	 * @param array<string, mixed>|null $before_data State before the change.
	 * @param array<string, mixed>|null $after_data  State after the change.
	 * @param int|null                  $object_id    Affected WordPress object ID.
	 * @throws PersistenceException When snapshot encoding or database insertion fails.
	 */
	public function record_once(
		int $operation_id,
		int $item_id,
		string $change_key,
		string $change_type,
		?array $before_data,
		?array $after_data,
		?int $object_id = null
	): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom journal table has no core API or object cache.
		$result = $this->database->query(
			$this->database->prepare(
				'INSERT INTO %i
					(operation_id, item_id, change_key, object_id, change_type, before_data, after_data, created_at)
				VALUES (%d, %d, %s, NULLIF(%d, 0), %s, NULLIF(%s, \'\'), NULLIF(%s, \'\'), %s)
				ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
				$this->table,
				$operation_id,
				$item_id,
				$change_key,
				$object_id,
				$change_type,
				null === $before_data ? '' : Json::encode( $before_data ),
				null === $after_data ? '' : Json::encode( $after_data ),
				current_time( 'mysql', true )
			)
		);

		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new PersistenceException( 'The change journal entry could not be recorded.' );
		}

		return (int) $this->database->insert_id;
	}

	/**
	 * Returns the actual changes for an operation in journal order.
	 *
	 * @param int $operation_id Operation ID whose changes are requested.
	 * @return list<array<string, mixed>>
	 * @throws \JsonException       When a stored snapshot is invalid JSON.
	 * @throws PersistenceException When a stored snapshot is not an array.
	 */
	public function find_for_operation( int $operation_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom journal table has no core API or object cache.
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
	 * Marks a journal entry as undone without changing its recorded snapshots.
	 *
	 * @param int $change_id Change journal row ID.
	 */
	public function mark_undone( int $change_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom journal table has no core API or object cache.
		$result = $this->database->update(
			$this->table,
			array( 'undone_at' => current_time( 'mysql', true ) ),
			array(
				'id'        => $change_id,
				'undone_at' => null,
			)
		);

		return 1 === $result;
	}

	/**
	 * Normalizes a change journal row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 * @throws \JsonException       When a stored snapshot is invalid JSON.
	 * @throws PersistenceException When a stored snapshot is not an array.
	 */
	private function normalize_row( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['operation_id'] = (int) $row['operation_id'];
		$row['item_id']      = (int) $row['item_id'];
		$row['object_id']    = null === $row['object_id'] ? null : (int) $row['object_id'];
		$row['before_data']  = null === $row['before_data']
			? null
			: Json::decode( (string) $row['before_data'] );
		$row['after_data']   = null === $row['after_data']
			? null
			: Json::decode( (string) $row['after_data'] );

		return $row;
	}
}
