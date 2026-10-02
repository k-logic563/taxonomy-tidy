<?php
/**
 * Persistence repository integration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use TermSteward\Domain\Operation\Action;
use TermSteward\Domain\Operation\InvalidStatusTransition;
use TermSteward\Domain\Operation\Status;
use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Infrastructure\Database\Schema;
use TermSteward\Infrastructure\Database\Tables;
use TermSteward\Infrastructure\Persistence\ChangeJournalRepository;
use TermSteward\Infrastructure\Persistence\OperationItemRepository;
use TermSteward\Infrastructure\Persistence\OperationLock;
use TermSteward\Infrastructure\Persistence\OperationRepository;
use TermSteward\Infrastructure\Persistence\PersistenceException;
use WP_UnitTestCase;

/**
 * Verifies operation, item, journal, and lock persistence behavior.
 */
final class PersistenceTest extends WP_UnitTestCase {
	/**
	 * Ensures the schema exists and starts each test with empty plugin tables.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->delete_plugin_rows();
	}

	/**
	 * Removes persisted test records.
	 */
	public function tear_down(): void {
		$this->delete_plugin_rows();
		parent::tear_down();
	}

	/**
	 * A repository persists and advances the complete success path.
	 */
	public function test_operation_follows_valid_state_path(): void {
		global $wpdb;

		$repository   = new OperationRepository( $wpdb );
		$operation_id = $repository->create( 7, Taxonomy::CATEGORY, array( 'label' => 'test' ) );
		$repository->save_preview_context(
			$operation_id,
			str_repeat( 'a', 64 ),
			str_repeat( 'b', 64 ),
			array( 'label' => 'normalized' )
		);

		$repository->transition( $operation_id, Status::PREVIEWED );
		$repository->transition( $operation_id, Status::RUNNING );
		$repository->transition( $operation_id, Status::COMPLETED );
		$repository->save_result(
			$operation_id,
			array( 'changed' => 3 ),
			array(),
			array( 'retained' => 1 )
		);

		$operation = $repository->find( $operation_id );

		$this->assertIsArray( $operation );
		$this->assertSame( Status::COMPLETED->value, $operation['status'] );
		$this->assertSame( array( 'label' => 'normalized' ), $operation['requested_data'] );
		$this->assertSame( str_repeat( 'a', 64 ), $operation['plan_hash'] );
		$this->assertSame( array( 'changed' => 3 ), $operation['result_data'] );
		$this->assertSame( array( 'retained' => 1 ), $operation['warnings'] );
		$this->assertNotNull( $operation['started_at'] );
		$this->assertNotNull( $operation['completed_at'] );

		$undo_id = $repository->create( 7, Taxonomy::CATEGORY, array(), $operation_id );
		$repository->transition( $undo_id, Status::UNDO_PREVIEWED );
		$repository->transition( $undo_id, Status::UNDOING );
		$repository->transition( $undo_id, Status::UNDONE );
		$undo = $repository->find( $undo_id );

		$this->assertIsArray( $undo );
		$this->assertSame( $operation_id, $undo['parent_operation_id'] );
		$this->assertSame( Status::UNDONE->value, $undo['status'] );
	}

	/**
	 * Invalid repository transitions are rejected before persistence.
	 */
	public function test_operation_rejects_invalid_state_path(): void {
		global $wpdb;

		$repository   = new OperationRepository( $wpdb );
		$operation_id = $repository->create( 7, Taxonomy::POST_TAG );

		$this->expectException( InvalidStatusTransition::class );
		$repository->transition( $operation_id, Status::COMPLETED );
	}

	/**
	 * Draft writes and discard cannot affect another owner or a running operation.
	 */
	public function test_editable_operation_writes_enforce_owner_and_status(): void {
		global $wpdb;

		$operations   = new OperationRepository( $wpdb );
		$items        = new OperationItemRepository( $wpdb );
		$operation_id = $operations->create( 7, Taxonomy::CATEGORY );
		$item_id      = $items->add( $operation_id, 'rename:12', Action::RENAME, array( 'term_id' => 12 ) );

		try {
			$operations->discard( $operation_id, 8 );
			$this->fail( 'Another owner must not discard an operation.' );
		} catch ( PersistenceException ) {
			$this->assertNotNull( $items->find_for_operation( $operation_id )[0] ?? null );
		}

		$operations->save_preview_context( $operation_id, str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), array() );
		$operations->transition( $operation_id, Status::PREVIEWED );
		$operations->transition( $operation_id, Status::RUNNING );
		$this->expectException( PersistenceException::class );
		$operations->save_draft(
			$operation_id,
			7,
			array(
				'plan'    => array(),
				'item_id' => $item_id,
			)
		);
	}

	/**
	 * An attempted but interrupted item remains discoverable for retry.
	 */
	public function test_pending_item_remains_discoverable_after_attempt(): void {
		global $wpdb;

		$operations   = new OperationRepository( $wpdb );
		$items        = new OperationItemRepository( $wpdb );
		$operation_id = $operations->create( 7, Taxonomy::CATEGORY );
		$item_id      = $items->add(
			$operation_id,
			'rename:12',
			Action::RENAME,
			array( 'term_id' => 12 )
		);

		$this->assertTrue( $items->record_attempt( $item_id ) );

		$pending = $items->find_pending( $operation_id, 10 );

		$this->assertCount( 1, $pending );
		$this->assertSame( $item_id, $pending[0]['id'] );
		$this->assertSame( 1, $pending[0]['attempts'] );
		$this->assertTrue( $items->mark_completed( $item_id ) );
		$this->assertSame( array(), $items->find_pending( $operation_id, 10 ) );

		$failed_id = $items->add( $operation_id, 'delete:14', Action::DELETE, array() );
		$this->assertTrue( $items->mark_failed( $failed_id, 'Expected test failure.' ) );
		$this->assertSame( array(), $items->find_pending( $operation_id, 10 ) );

		$stored_items = $items->find_for_operation( $operation_id );

		$this->assertCount( 2, $stored_items );
		$this->assertSame( 'Expected test failure.', $stored_items[1]['last_error'] );
	}

	/** Progress is derived from mutually exclusive persisted item states. */
	public function test_item_progress_counts_are_complete_and_consistent(): void {
		global $wpdb;

		$operations   = new OperationRepository( $wpdb );
		$items        = new OperationItemRepository( $wpdb );
		$operation_id = $operations->create( 7, Taxonomy::POST_TAG );
		$completed    = $items->add( $operation_id, 'progress:completed', Action::RENAME, array() );
		$failed       = $items->add( $operation_id, 'progress:failed', Action::DELETE, array() );
		$skipped      = $items->add( $operation_id, 'progress:skipped', Action::MERGE, array() );
		$items->add( $operation_id, 'progress:pending', Action::RENAME, array() );
		$this->assertTrue( $items->mark_completed( $completed ) );
		$this->assertTrue( $items->mark_failed( $failed, 'Expected progress failure.' ) );
		$this->assertTrue( $items->mark_skipped( $skipped, 'source_retained' ) );

		$progress = $items->progress( $operation_id );

		$this->assertSame(
			array(
				'total'     => 4,
				'pending'   => 1,
				'completed' => 1,
				'failed'    => 1,
				'skipped'   => 1,
			),
			$progress
		);
		$this->assertSame( $progress['total'], $progress['completed'] + $progress['pending'] + $progress['failed'] + $progress['skipped'] );
	}

	/**
	 * Only one operation can hold the lock for a taxonomy.
	 */
	public function test_taxonomy_lock_rejects_a_conflicting_operation(): void {
		global $wpdb;

		$operations = new OperationRepository( $wpdb );
		$lock       = new OperationLock( $wpdb );
		$first_id   = $operations->create( 7, Taxonomy::CATEGORY );
		$second_id  = $operations->create( 8, Taxonomy::CATEGORY );
		$tag_id     = $operations->create( 8, Taxonomy::POST_TAG );
		$token      = $lock->acquire( $first_id );

		$this->assertIsString( $token );
		$this->assertNull( $lock->acquire( $second_id ) );
		$this->assertIsString( $lock->acquire( $tag_id ) );
		$this->assertFalse( $lock->release( $first_id, 'wrong-token' ) );
		$this->assertTrue( $lock->release( $first_id, $token ) );
		$this->assertIsString( $lock->acquire( $second_id ) );
	}

	/**
	 * An expired operation lock does not block recovery indefinitely.
	 */
	public function test_expired_lock_can_be_reacquired_by_another_operation(): void {
		global $wpdb;

		$operations = new OperationRepository( $wpdb );
		$lock       = new OperationLock( $wpdb );
		$first_id   = $operations->create( 7, Taxonomy::CATEGORY );
		$second_id  = $operations->create( 8, Taxonomy::CATEGORY );

		$this->assertIsString( $lock->acquire( $first_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- This simulates expiry in the isolated custom test table.
		$wpdb->update(
			Tables::operations( $wpdb ),
			array( 'lock_expires_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $first_id )
		);

		$this->assertIsString( $lock->acquire( $second_id ) );
	}

	/**
	 * Retrying the same journal key does not create a duplicate row.
	 */
	public function test_change_journal_is_idempotent(): void {
		global $wpdb;

		$operations   = new OperationRepository( $wpdb );
		$items        = new OperationItemRepository( $wpdb );
		$journal      = new ChangeJournalRepository( $wpdb );
		$operation_id = $operations->create( 7, Taxonomy::POST_TAG );
		$item_id      = $items->add( $operation_id, 'merge:4:9', Action::MERGE, array() );
		$first_id     = $journal->record_once(
			$operation_id,
			$item_id,
			'post:25:destination',
			'relationship_added',
			array( 'assigned' => false ),
			array( 'assigned' => true ),
			25
		);
		$retry_id     = $journal->record_once(
			$operation_id,
			$item_id,
			'post:25:destination',
			'relationship_added',
			array( 'assigned' => false ),
			array( 'assigned' => true ),
			25
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Assertion against the isolated custom test table.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE operation_id = %d',
				Tables::changes( $wpdb ),
				$operation_id
			)
		);

		$this->assertSame( $first_id, $retry_id );
		$this->assertSame( 1, (int) $count );

		$changes = $journal->find_for_operation( $operation_id );

		$this->assertCount( 1, $changes );
		$this->assertSame( array( 'assigned' => false ), $changes[0]['before_data'] );
		$this->assertTrue( $journal->mark_undone( $first_id ) );
		$this->assertFalse( $journal->mark_undone( $first_id ) );
	}

	/** History preview is capped and prioritizes errors, warnings, then recent results. */
	public function test_change_journal_history_summary_is_bounded_and_prioritized(): void {
		global $wpdb;
		$operations   = new OperationRepository( $wpdb );
		$items        = new OperationItemRepository( $wpdb );
		$journal      = new ChangeJournalRepository( $wpdb );
		$operation_id = $operations->create( 7, Taxonomy::POST_TAG );
		$types        = array( 'name_changed', 'slug_changed', 'destination_added', 'source_removed', 'source_retained', 'item_failed' );
		foreach ( $types as $index => $type ) {
			$item_id = $items->add( $operation_id, 'history:' . $index, Action::RENAME, array( 'before' => array( 'name' => 'Log ' . $index ) ) );
			$journal->record_once( $operation_id, $item_id, 'history:' . $index, $type, array( 'name' => 'Before ' . $index ), array( 'name' => 'After ' . $index ) );
		}

		$preview = $journal->history_preview( $operation_id );
		$counts  = $journal->history_counts( $operation_id );
		$page    = $journal->history_page( $operation_id, 1, 3 );

		$this->assertCount( 5, $preview );
		$this->assertSame( 'item_failed', $preview[0]['change_type'] );
		$this->assertSame( 'source_retained', $preview[1]['change_type'] );
		$this->assertSame( array( 'before' => array( 'name' => 'Log 5' ) ), $preview[0]['item_payload'] );
		$this->assertSame(
			array(
				'total'   => 6,
				'success' => 4,
				'warning' => 1,
				'error'   => 1,
			),
			$counts
		);
		$this->assertSame( 2, $page['total_pages'] );
		$this->assertCount( 3, $page['items'] );
	}

	/**
	 * Deletes all custom rows from the isolated WordPress test database.
	 */
	private function delete_plugin_rows(): void {
		global $wpdb;

		foreach (
			array(
				Tables::changes( $wpdb ),
				Tables::items( $wpdb ),
				Tables::operations( $wpdb ),
			) as $table
		) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- This clears only isolated custom test tables.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}
