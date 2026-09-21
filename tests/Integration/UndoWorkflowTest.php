<?php
/**
 * Phase 6 Undo integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Application\Undo\UndoItemExecutor;
use TaxonomyTidy\Application\Undo\UndoPlanner;
use TaxonomyTidy\Application\Undo\UndoWorkflow;
use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Database\Schema;
use TaxonomyTidy\Infrastructure\Database\Tables;
use TaxonomyTidy\Infrastructure\Persistence\ChangeJournalRepository;
use TaxonomyTidy\Infrastructure\Persistence\DatabaseTransaction;
use TaxonomyTidy\Infrastructure\Persistence\OperationItemRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationLock;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;
use WP_Term;
use WP_UnitTestCase;

/** Verifies current-state validation, inverse mutations, batching, and audit links. */
final class UndoWorkflowTest extends WP_UnitTestCase {
	/** Installs and clears custom persistence. */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->clear_rows();
	}

	/** Clears custom persistence. */
	public function tear_down(): void {
		$this->clear_rows();
		parent::tear_down();
	}

	/** A current rename is restored without changing its unrelated slug. */
	public function test_rename_undo_uses_separate_operation_and_preserves_slug(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'After',
				'slug'     => 'stable-slug',
			)
		);
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$item_id  = $this->items()->add(
			$original,
			'rename:' . $term_id,
			Action::RENAME,
			array(
				'kind'    => 'rename',
				'term_id' => $term_id,
			)
		);
		$this->items()->mark_completed( $item_id );
		$change_id = $this->journal()->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Before' ), array( 'name' => 'After' ), $term_id );

		$assessment = $this->planner()->assess( $original, $user_id );
		$this->assertSame( 'full', $assessment['availability'] );
		$undo   = $this->planner()->preview( $original, $user_id );
		$result = $this->workflow()->run_batch( (int) $undo['id'], $user_id );
		$term   = get_term( $term_id, 'post_tag' );

		$this->assertSame( Status::UNDONE->value, $result['status'] );
		$this->assertSame( $original, $result['parent_operation_id'] );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'Before', $term->name );
		$this->assertSame( 'stable-slug', $term->slug );
		$this->assertNotNull( $this->journal()->find_for_operation( $original )[0]['undone_at'] );
		$this->assertSame( 'name_restored', $this->journal()->find_for_operation( (int) $undo['id'] )[0]['change_type'] );
		$this->assertGreaterThan( 0, $change_id );
	}

	/** Repeated preview requests reuse one child and never seed items or journals. */
	public function test_repeated_preview_reuses_the_only_child_operation(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'After preview',
				'slug'     => 'one-preview',
			)
		);
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$item_id  = $this->items()->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
		$this->items()->mark_completed( $item_id );
		$this->journal()->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Before preview' ), array( 'name' => 'After preview' ), $term_id );

		$first  = $this->planner()->preview( $original, $user_id );
		$second = $this->planner()->preview( $original, $user_id );

		$this->assertSame( $first['id'], $second['id'] );
		$this->assertCount( 1, $this->operations()->undos( $original ) );
		$this->assertSame( array(), $this->items()->find_for_operation( (int) $first['id'] ) );
		$this->assertSame( array(), $this->journal()->find_for_operation( (int) $first['id'] ) );
	}

	/** The original-scoped preview lease rejects overlap and is released on failure. */
	public function test_preview_lock_is_original_scoped_and_failure_releases_it(): void {
		global $wpdb;
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$first   = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$second  = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		foreach ( array( $first, $second ) as $index => $original ) {
			$term_id = self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => 'Lock after ' . $index,
					'slug'     => 'lock-preview-' . $index,
				)
			);
			$item_id = $this->items()->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
			$this->items()->mark_completed( $item_id );
			$this->journal()->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Lock before ' . $index ), array( 'name' => 'Lock after ' . $index ), $term_id );
		}
		$lock  = new OperationLock( $wpdb );
		$token = $lock->acquire_undo_parent( $first );
		$this->assertIsString( $token );
		$this->assertNotSame( null, $this->planner()->preview( $second, $user_id ) );
		try {
			$this->planner()->preview( $first, $user_id );
			$this->fail( 'The same original must not enter preview concurrently.' );
		} catch ( \TaxonomyTidy\Application\Undo\UndoException $exception ) {
			$this->assertSame( \TaxonomyTidy\Application\Undo\UndoErrorCode::LOCKED, $exception->error_code() );
		}
		$this->assertTrue( $lock->release( $first, $token ) );
		$this->assertNotSame( null, $this->planner()->preview( $first, $user_id ) );
	}

	/** A failure after acquiring the original-scoped lease always releases it. */
	public function test_preview_releases_lock_when_legacy_duplicate_is_detected(): void {
		global $wpdb;
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Duplicate after',
				'slug'     => 'duplicate-preview',
			)
		);
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$item_id  = $this->items()->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
		$this->items()->mark_completed( $item_id );
		$this->journal()->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Duplicate before' ), array( 'name' => 'Duplicate after' ), $term_id );
		$this->operations()->create( $user_id, Taxonomy::POST_TAG, array( 'kind' => 'undo' ), $original );
		$this->operations()->create( $user_id, Taxonomy::POST_TAG, array( 'kind' => 'undo' ), $original );

		try {
			$this->planner()->preview( $original, $user_id );
			$this->fail( 'Legacy duplicate children must be rejected.' );
		} catch ( \TaxonomyTidy\Application\Undo\UndoException $exception ) {
			$this->assertSame( \TaxonomyTidy\Application\Undo\UndoErrorCode::DUPLICATE, $exception->error_code() );
		}

		$lock  = new OperationLock( $wpdb );
		$token = $lock->acquire_undo_parent( $original );
		$this->assertIsString( $token );
		$this->assertTrue( $lock->release( $original, $token ) );
		$this->assertCount( 2, $this->operations()->undos( $original ) );
	}

	/** Started and completed Undo children prevent a second child from being created. */
	public function test_started_and_completed_undo_reject_new_preview(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Terminal after',
				'slug'     => 'terminal-undo',
			)
		);
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$item_id  = $this->items()->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
		$this->items()->mark_completed( $item_id );
		$this->journal()->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Terminal before' ), array( 'name' => 'Terminal after' ), $term_id );
		$undo = $this->planner()->preview( $original, $user_id );
		$this->workflow()->run_batch( (int) $undo['id'], $user_id );

		try {
			$this->planner()->preview( $original, $user_id );
			$this->fail( 'A completed Undo must prevent re-Undo.' );
		} catch ( \TaxonomyTidy\Application\Undo\UndoException $exception ) {
			$this->assertSame( \TaxonomyTidy\Application\Undo\UndoErrorCode::ALREADY_UNDONE, $exception->error_code() );
		}
		$this->assertCount( 1, $this->operations()->undos( $original ) );
	}

	/** A later administrator rename is a conflict and is never overwritten. */
	public function test_rename_conflict_is_not_guessed_or_overwritten(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Administrator value',
				'slug'     => 'rename-conflict',
			)
		);
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$item_id  = $this->items()->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
		$this->items()->mark_completed( $item_id );
		$this->journal()->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Before' ), array( 'name' => 'Original result' ), $term_id );

		$assessment = $this->planner()->assess( $original, $user_id );

		$this->assertSame( 'none', $assessment['availability'] );
		$this->assertSame( 'Administrator value', get_term( $term_id, 'post_tag' )->name );
	}

	/** Merge Undo recreates a deleted source and removes only newly added destinations. */
	public function test_merge_undo_restores_sources_and_preserves_existing_destination(): void {
		$user_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source      = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Old source',
				'slug'     => 'old-source',
			)
		);
		$destination = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Destination',
				'slug'     => 'destination',
			)
		);
		$source_term = get_term( $source, 'post_tag' );
		$dest_term   = get_term( $destination, 'post_tag' );
		$this->assertInstanceOf( WP_Term::class, $source_term );
		$this->assertInstanceOf( WP_Term::class, $dest_term );
		$source_snapshot = $this->snapshot( $source_term );
		$dest_snapshot   = $this->snapshot( $dest_term );
		$first_post      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$second_post     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_object_terms( $first_post, array( $destination ), 'post_tag' );
		wp_set_object_terms( $second_post, array( $destination ), 'post_tag' );
		wp_delete_term( $source, 'post_tag' );

		$original   = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$first_item = $this->merge_item( $original, $first_post, $source, $destination, $source_snapshot, $dest_snapshot, false );
		$this->journal()->record_once( $original, $first_item, 'first:destination', 'destination_added', array( 'assigned' => false ), array( 'assigned' => true ), $first_post );
		$this->journal()->record_once( $original, $first_item, 'first:source', 'source_removed', array( 'assigned' => true ), array( 'assigned' => false ), $first_post );
		$second_item = $this->merge_item( $original, $second_post, $source, $destination, $source_snapshot, $dest_snapshot, true );
		$this->journal()->record_once( $original, $second_item, 'second:destination', 'destination_existing', array( 'assigned' => true ), array( 'assigned' => true ), $second_post );
		$this->journal()->record_once( $original, $second_item, 'second:source', 'source_removed', array( 'assigned' => true ), array( 'assigned' => false ), $second_post );
		$finalize = $this->items()->add( $original, 'finalize:' . $source, Action::MERGE, array( 'kind' => 'merge_finalize' ) );
		$this->items()->mark_completed( $finalize );
		$this->journal()->record_once( $original, $finalize, 'source:deleted', 'source_deleted', $source_snapshot, array( 'deleted' => true ), $source );

		$undo     = $this->planner()->preview( $original, $user_id );
		$result   = $this->workflow()->run_batch( (int) $undo['id'], $user_id );
		$restored = get_term_by( 'slug', 'old-source', 'post_tag' );

		$this->assertSame( Status::UNDONE->value, $result['status'] );
		$this->assertInstanceOf( WP_Term::class, $restored );
		$this->assertNotSame( $source, $restored->term_id );
		$this->assertTrue( has_term( $restored->term_id, 'post_tag', $first_post ) );
		$this->assertFalse( has_term( $destination, 'post_tag', $first_post ) );
		$this->assertTrue( has_term( $restored->term_id, 'post_tag', $second_post ) );
		$this->assertTrue( has_term( $destination, 'post_tag', $second_post ) );
		$this->assertSame( $restored->term_id, $this->journal()->restored_term_id( (int) $undo['id'], $source ) );
	}

	/** A slug collision blocks deletion Undo without generating a substitute slug. */
	public function test_deleted_term_slug_conflict_is_unavailable(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$deleted = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Deleted',
				'slug'     => 'restore-collision',
			)
		);
		$term    = get_term( $deleted, 'post_tag' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$snapshot = $this->snapshot( $term );
		wp_delete_term( $deleted, 'post_tag' );
		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'New use',
				'slug'     => 'restore-collision',
			)
		);
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$item_id  = $this->items()->add( $original, 'delete:' . $deleted, Action::DELETE, array() );
		$this->items()->mark_completed( $item_id );
		$this->journal()->record_once( $original, $item_id, 'term:deleted', 'term_deleted', $snapshot, array( 'deleted' => true ), $deleted );

		$assessment = $this->planner()->assess( $original, $user_id );

		$this->assertSame( 'none', $assessment['availability'] );
		$this->assertSame( 'term_or_slug_exists', $assessment['conflicts'][0]['reason'] );
		$this->assertSame(
			1,
			count(
				get_terms(
					array(
						'taxonomy'   => 'post_tag',
						'slug'       => 'restore-collision',
						'hide_empty' => false,
					)
				)
			)
		);
	}

	/** Safe items are kept while a pre-existing conflict produces a partial result. */
	public function test_partial_undo_does_not_overwrite_conflicted_item(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$safe     = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Safe after',
				'slug'     => 'safe-partial',
			)
		);
		$conflict = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Later administrator value',
				'slug'     => 'conflict-partial',
			)
		);
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		foreach ( array(
			$safe     => array( 'Safe before', 'Safe after' ),
			$conflict => array( 'Conflict before', 'Original after' ),
		) as $term_id => $names ) {
			$item_id = $this->items()->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
			$this->items()->mark_completed( $item_id );
			$this->journal()->record_once( $original, $item_id, 'rename:' . $term_id, 'name_changed', array( 'name' => $names[0] ), array( 'name' => $names[1] ), $term_id );
		}
		$assessment = $this->planner()->assess( $original, $user_id );
		$this->assertSame( 'partial', $assessment['availability'] );
		$this->assertSame( array( 'name' => 'Conflict before' ), $assessment['conflicts'][0]['planned'] );
		$this->assertSame( array( 'name' => 'Later administrator value' ), $assessment['conflicts'][0]['current'] );

		$undo   = $this->planner()->preview( $original, $user_id );
		$result = $this->workflow()->run_batch( (int) $undo['id'], $user_id );

		$this->assertSame( Status::UNDO_PARTIAL_FAILED->value, $result['status'] );
		$this->assertSame( 'Safe before', get_term( $safe, 'post_tag' )->name );
		$this->assertSame( 'Later administrator value', get_term( $conflict, 'post_tag' )->name );
	}

	/** A state change after preview invalidates the preview before Undo starts. */
	public function test_stale_undo_preview_starts_no_mutation(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Preview result',
				'slug'     => 'stale-undo',
			)
		);
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$item_id  = $this->items()->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
		$this->items()->mark_completed( $item_id );
		$this->journal()->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Preview before' ), array( 'name' => 'Preview result' ), $term_id );
		$undo = $this->planner()->preview( $original, $user_id );
		wp_update_term( $term_id, 'post_tag', array( 'name' => 'Changed after preview' ) );

		try {
			$this->workflow()->run_batch( (int) $undo['id'], $user_id );
			$this->fail( 'A stale Undo preview must not start.' );
		} catch ( \TaxonomyTidy\Application\Undo\UndoException $exception ) {
			$this->assertSame( \TaxonomyTidy\Application\Undo\UndoErrorCode::STALE_PREVIEW, $exception->error_code() );
		}
		$this->assertSame( Status::UNDO_PREVIEWED->value, $this->operations()->find( (int) $undo['id'] )['status'] );
		$this->assertSame( 'Changed after preview', get_term( $term_id, 'post_tag' )->name );
		$this->assertSame( array(), $this->items()->find_for_operation( (int) $undo['id'] ) );
	}

	/** Thirty inverse items remain bounded to ten and finish in three requests. */
	public function test_undo_is_bounded_and_resumable(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		for ( $index = 0; $index < 30; ++$index ) {
			$term_id = self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => 'After ' . $index,
					'slug'     => 'batch-' . $index,
				)
			);
			$item_id = $this->items()->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
			$this->items()->mark_completed( $item_id );
			$this->journal()->record_once( $original, $item_id, 'rename:' . $term_id, 'name_changed', array( 'name' => 'Before ' . $index ), array( 'name' => 'After ' . $index ), $term_id );
		}
		$undo  = $this->planner()->preview( $original, $user_id );
		$first = $this->workflow()->run_batch( (int) $undo['id'], $user_id );
		$this->assertSame( Status::UNDOING->value, $first['status'] );
		$this->assertSame( 10, $first['progress']['completed'] );
		$this->assertSame( 20, $first['progress']['pending'] );

		$second = $this->workflow()->run_batch( (int) $undo['id'], $user_id );
		$this->assertSame( Status::UNDOING->value, $second['status'] );
		$this->assertSame( 20, $second['progress']['completed'] );
		$this->assertSame( 10, $second['progress']['pending'] );

		$final = $this->workflow()->run_batch( (int) $undo['id'], $user_id );
		$this->assertSame( Status::UNDONE->value, $final['status'] );
		$this->assertSame( 30, $final['progress']['completed'] );
		$this->assertSame( 0, $final['progress']['pending'] );
	}

	/** A conflict introduced between batches is journaled with planned and current state. */
	public function test_between_batch_conflict_records_current_state(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$original = $this->completed_operation( $user_id, Taxonomy::POST_TAG );
		$last_id  = 0;
		for ( $index = 0; $index < 11; ++$index ) {
			$last_id = self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => 'Runtime after ' . $index,
					'slug'     => 'runtime-' . $index,
				)
			);
			$item_id = $this->items()->add( $original, 'rename:' . $last_id, Action::RENAME, array() );
			$this->items()->mark_completed( $item_id );
			$this->journal()->record_once( $original, $item_id, 'rename:' . $last_id, 'name_changed', array( 'name' => 'Runtime before ' . $index ), array( 'name' => 'Runtime after ' . $index ), $last_id );
		}
		$undo  = $this->planner()->preview( $original, $user_id );
		$first = $this->workflow()->run_batch( (int) $undo['id'], $user_id );
		$this->assertSame( Status::UNDOING->value, $first['status'] );
		wp_update_term( $last_id, 'post_tag', array( 'name' => 'Runtime administrator change' ) );

		$final    = $this->workflow()->run_batch( (int) $undo['id'], $user_id );
		$failures = array_values( array_filter( $this->journal()->find_for_operation( (int) $undo['id'] ), static fn( array $change ): bool => 'undo_item_failed' === $change['change_type'] ) );

		$this->assertSame( Status::UNDO_PARTIAL_FAILED->value, $final['status'] );
		$this->assertCount( 1, $failures );
		$this->assertSame( 'Runtime before 10', $failures[0]['before_data']['planned']['name'] );
		$this->assertSame( 'Runtime administrator change', $failures[0]['after_data']['current']['name'] );
		$this->assertTrue( $failures[0]['after_data']['retryable'] );
	}

	/**
	 * Creates an already completed original operation.
	 *
	 * @param int      $user_id  Owner administrator ID.
	 * @param Taxonomy $taxonomy Operation taxonomy.
	 */
	private function completed_operation( int $user_id, Taxonomy $taxonomy ): int {
		$id = $this->operations()->create(
			$user_id,
			$taxonomy,
			array(
				'plan' => array(
					array(
						'action'  => 'rename',
						'sources' => array( array( 'term_id' => 1 ) ),
					),
				),
			)
		);
		$this->operations()->transition( $id, Status::PREVIEWED );
		$this->operations()->transition( $id, Status::RUNNING );
		$this->operations()->transition( $id, Status::COMPLETED );
		return $id;
	}

	/**
	 * Adds one completed original merge-post item.
	 *
	 * @param int                  $operation_id      Original operation ID.
	 * @param int                  $post_id           Published post ID.
	 * @param int                  $source_id         Source term ID.
	 * @param int                  $destination_id    Destination term ID.
	 * @param array<string, mixed> $source            Source snapshot.
	 * @param array<string, mixed> $destination       Destination snapshot.
	 * @param bool                 $destination_present Whether destination existed before merge.
	 */
	private function merge_item( int $operation_id, int $post_id, int $source_id, int $destination_id, array $source, array $destination, bool $destination_present ): int {
		$item_id = $this->items()->add(
			$operation_id,
			'merge:' . $post_id,
			Action::MERGE,
			array(
				'kind'                 => 'merge_post',
				'post_id'              => $post_id,
				'source_id'            => $source_id,
				'destination_id'       => $destination_id,
				'source_snapshot'      => $source,
				'destination_snapshot' => $destination,
				'destination_present'  => $destination_present,
			)
		);
		$this->items()->mark_completed( $item_id );
		return $item_id;
	}

	/**
	 * Returns the stable term fields used by execution and Undo.
	 *
	 * @param WP_Term $term Term to snapshot.
	 */
	private function snapshot( WP_Term $term ): array {
		return array(
			'term_id'          => $term->term_id,
			'term_taxonomy_id' => (int) $term->term_taxonomy_id,
			'taxonomy'         => $term->taxonomy,
			'name'             => $term->name,
			'slug'             => $term->slug,
			'description'      => $term->description,
			'parent'           => (int) $term->parent,
		);
	}

	/** Returns operation storage. */
	private function operations(): OperationRepository {
		return new OperationRepository( $GLOBALS['wpdb'] );
	}

	/** Returns operation-item storage. */
	private function items(): OperationItemRepository {
		return new OperationItemRepository( $GLOBALS['wpdb'] );
	}

	/** Returns change-journal storage. */
	private function journal(): ChangeJournalRepository {
		return new ChangeJournalRepository( $GLOBALS['wpdb'] );
	}

	/** Returns a current-state Undo planner. */
	private function planner(): UndoPlanner {
		return new UndoPlanner( $this->operations(), $this->items(), $this->journal(), new OperationLock( $GLOBALS['wpdb'] ) );
	}

	/** Returns a fully wired bounded Undo workflow. */
	private function workflow(): UndoWorkflow {
		global $wpdb;
		$journal = $this->journal();
		return new UndoWorkflow( $this->operations(), $this->items(), new OperationLock( $wpdb ), new UndoPlanner( $this->operations(), $this->items(), $journal, new OperationLock( $wpdb ) ), new UndoItemExecutor( $journal ), new DatabaseTransaction( $wpdb ) );
	}

	/** Clears only the isolated plugin persistence tables. */
	private function clear_rows(): void {
		global $wpdb;
		foreach ( array( Tables::changes( $wpdb ), Tables::items( $wpdb ), Tables::operations( $wpdb ) ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated integration tables are intentionally cleared.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}
