<?php
/**
 * Change journal persistence.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Infrastructure\Persistence;

use TermSteward\Infrastructure\Database\Tables;
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
	 * Returns a bounded page of journal rows with their item context.
	 *
	 * @param int $operation_id Operation ID whose changes are requested.
	 * @param int $page         One-based page number.
	 * @param int $per_page     Page size, capped to protect the response.
	 * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function history_page( int $operation_id, int $page, int $per_page ): array {
		$per_page    = max( 1, min( 100, $per_page ) );
		$total       = $this->count_for_operation( $operation_id );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = max( 1, min( $page, $total_pages ) );
		$rows        = $this->database->get_results(
			$this->database->prepare(
				'SELECT changes.*, items.payload AS item_payload
				FROM %i AS changes
				INNER JOIN %i AS items ON items.id = changes.item_id AND items.operation_id = changes.operation_id
				WHERE changes.operation_id = %d
				ORDER BY changes.id ASC LIMIT %d OFFSET %d',
				$this->table,
				Tables::items( $this->database ),
				$operation_id,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		return array(
			'items'       => array_map( array( $this, 'normalize_history_row' ), is_array( $rows ) ? $rows : array() ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Returns at most five representative rows, prioritizing failures and warnings.
	 *
	 * @param int $operation_id Operation ID whose summary is requested.
	 * @param int $limit        Maximum preview rows.
	 * @return list<array<string, mixed>>
	 */
	public function history_preview( int $operation_id, int $limit = 5 ): array {
		$limit = max( 1, min( 5, $limit ) );
		$rows  = $this->database->get_results(
			$this->database->prepare(
				'SELECT changes.*, items.payload AS item_payload
				FROM %i AS changes
				INNER JOIN %i AS items ON items.id = changes.item_id AND items.operation_id = changes.operation_id
				WHERE changes.operation_id = %d
				ORDER BY CASE
					WHEN changes.change_type IN (\'item_failed\', \'undo_item_failed\') THEN 0
					WHEN changes.change_type = \'source_retained\' THEN 1
					ELSE 2 END ASC, changes.id DESC
				LIMIT %d',
				$this->table,
				Tables::items( $this->database ),
				$operation_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_history_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns journal totals grouped into user-facing severities.
	 *
	 * @param int $operation_id Operation ID whose summary is requested.
	 * @return array{total: int, success: int, warning: int, error: int}
	 */
	public function history_counts( int $operation_id ): array {
		$row     = $this->database->get_row(
			$this->database->prepare(
				'SELECT COUNT(*) AS total,
				SUM(change_type IN (\'item_failed\', \'undo_item_failed\')) AS error_count,
				SUM(change_type = \'source_retained\') AS warning_count
				FROM %i WHERE operation_id = %d',
				$this->table,
				$operation_id
			),
			ARRAY_A
		);
		$total   = (int) ( $row['total'] ?? 0 );
		$error   = (int) ( $row['error_count'] ?? 0 );
		$warning = (int) ( $row['warning_count'] ?? 0 );

		return array(
			'total'   => $total,
			'success' => max( 0, $total - $error - $warning ),
			'warning' => $warning,
			'error'   => $error,
		);
	}

	/**
	 * Counts journal rows without loading snapshots.
	 *
	 * @param int $operation_id Operation ID.
	 */
	private function count_for_operation( int $operation_id ): int {
		return (int) $this->database->get_var(
			$this->database->prepare( 'SELECT COUNT(*) FROM %i WHERE operation_id = %d', $this->table, $operation_id )
		);
	}

	/**
	 * Normalizes a journal row and its bounded item context.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_history_row( array $row ): array {
		$payload             = Json::decode( (string) $row['item_payload'] );
		$row                 = $this->normalize_row( $row );
		$row['item_payload'] = $payload;
		return $row;
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
	 * Marks an original change undone only when it belongs to the expected operation.
	 *
	 * @param int $change_id    Change row ID.
	 * @param int $operation_id Original operation ID.
	 */
	public function mark_undone_for_operation( int $change_id, int $operation_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom journal table has no core API or object cache.
		$result = $this->database->query(
			$this->database->prepare(
				'UPDATE %i SET undone_at = %s WHERE id = %d AND operation_id = %d AND undone_at IS NULL',
				$this->table,
				current_time( 'mysql', true ),
				$change_id,
				$operation_id
			)
		);
		return 1 === $result;
	}

	/**
	 * Counts inverse changes already recorded by an Undo operation.
	 *
	 * @param int    $operation_id Undo operation ID.
	 * @param string $change_key   Stable inverse key.
	 */
	public function has_change_key( int $operation_id, string $change_key ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom journal table has no core API or object cache.
		$count = $this->database->get_var(
			$this->database->prepare(
				'SELECT COUNT(*) FROM %i WHERE operation_id = %d AND change_key = %s',
				$this->table,
				$operation_id,
				$change_key
			)
		);
		return 0 < (int) $count;
	}

	/**
	 * Finds a term ID recreated by the current Undo operation.
	 *
	 * @param int $operation_id    Undo operation ID.
	 * @param int $original_term_id Deleted term ID from the original operation.
	 */
	public function restored_term_id( int $operation_id, int $original_term_id ): ?int {
		foreach ( $this->find_for_operation( $operation_id ) as $change ) {
			if ( 'term_restored' === $change['change_type'] && (int) ( $change['before_data']['original_term_id'] ?? 0 ) === $original_term_id ) {
				$term_id = (int) ( $change['after_data']['term_id'] ?? 0 );
				return 0 === $term_id ? null : $term_id;
			}
		}
		return null;
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
