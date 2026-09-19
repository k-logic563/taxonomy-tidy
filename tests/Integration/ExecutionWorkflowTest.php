<?php
/**
 * Phase 5 batched execution integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Application\Execution\ExecutionErrorCode;
use TaxonomyTidy\Application\Execution\ExecutionException;
use TaxonomyTidy\Application\Execution\ExecutionWorkflow;
use TaxonomyTidy\Application\Execution\ItemExecutor;
use TaxonomyTidy\Application\Planning\PlanService;
use TaxonomyTidy\Application\Planning\PlanWorkflow;
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

/** Verifies real mutations, fixed targets, batching, retry safety, and journals. */
final class ExecutionWorkflowTest extends WP_UnitTestCase {
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

	/** Rename changes only the approved values and records the actual term change. */
	public function test_rename_preserves_slug_and_records_journal(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id = $this->term( 'post_tag', 'Before name', 'stable-slug' );
		$preview = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->item( 'rename', array( $term_id ), array( 'new_name' => 'After name' ) ) ) );

		$result = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
		$term   = get_term( $term_id, 'post_tag' );

		$this->assertSame( Status::COMPLETED->value, $result['status'] );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'After name', $term->name );
		$this->assertSame( 'stable-slug', $term->slug );
		$changes = $this->journal()->find_for_operation( (int) $preview['id'] );
		$this->assertCount( 1, $changes );
		$this->assertSame( 'name_changed', $changes[0]['change_type'] );
		$this->assertSame( 'Before name', $changes[0]['before_data']['name'] );
		$this->assertSame( 'After name', $changes[0]['after_data']['name'] );
		$this->assertNull( $this->execution()->latest( $user_id, Taxonomy::POST_TAG ) );
	}

	/** Merge uses bounded batches, preserves excluded use, and is safe to continue. */
	public function test_merge_batches_fixed_posts_and_retains_source_used_by_draft(): void {
		$user_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source      = $this->term( 'post_tag', 'Merge source', 'merge-source' );
		$destination = $this->term( 'post_tag', 'Merge destination', 'merge-destination' );
		$unrelated   = $this->term( 'post_tag', 'Unrelated', 'unrelated' );
		$post_ids    = array();
		for ( $index = 0; $index < 12; ++$index ) {
			$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			$post_ids[] = $post_id;
			wp_set_object_terms( $post_id, array( $source, $unrelated ), 'post_tag' );
		}
		wp_set_object_terms( $post_ids[0], array( $destination ), 'post_tag', true );
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $draft_id, array( $source ), 'post_tag' );

		$preview = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->merge_item( array( $source ), $destination ) ) );
		$first   = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
		$this->assertSame( Status::RUNNING->value, $first['status'] );
		$this->assertSame( 10, $first['progress']['completed'] );
		$this->assertSame( 3, $first['progress']['pending'] );

		$final = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
		$this->assertSame( Status::COMPLETED->value, $final['status'] );
		$this->assertSame( 1, $final['progress']['skipped'] );
		foreach ( $post_ids as $post_id ) {
			$this->assertTrue( has_term( $destination, 'post_tag', $post_id ) );
			$this->assertFalse( has_term( $source, 'post_tag', $post_id ) );
			$this->assertTrue( has_term( $unrelated, 'post_tag', $post_id ) );
		}
		$this->assertTrue( has_term( $source, 'post_tag', $draft_id ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $source, 'post_tag' ) );
		$this->assertContains( 'source_retained', array_column( $this->journal()->find_for_operation( (int) $preview['id'] ), 'change_type' ) );

		try {
			$this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
			$this->fail( 'A completed operation must not execute again.' );
		} catch ( ExecutionException $exception ) {
			$this->assertSame( ExecutionErrorCode::INVALID_OPERATION, $exception->error_code() );
		}
	}

	/** Multiple sources sharing one post are removed without duplicate destinations. */
	public function test_merge_handles_multiple_sources_on_the_same_post(): void {
		$user_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$first       = $this->term( 'post_tag', 'First source', 'first-source' );
		$second      = $this->term( 'post_tag', 'Second source', 'second-source' );
		$destination = $this->term( 'post_tag', 'Shared destination', 'shared-destination' );
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_object_terms( $post_id, array( $first, $second ), 'post_tag' );
		$preview = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->merge_item( array( $first, $second ), $destination ) ) );

		$result = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );

		$this->assertSame( Status::COMPLETED->value, $result['status'] );
		$this->assertTrue( has_term( $destination, 'post_tag', $post_id ) );
		$this->assertFalse( has_term( $first, 'post_tag', $post_id ) );
		$this->assertFalse( has_term( $second, 'post_tag', $post_id ) );
		$this->assertCount( 1, wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) ) );
	}

	/** Unused deletion is journaled, while stale previews never begin execution. */
	public function test_delete_and_stale_preview_guard(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$deleted = $this->term( 'post_tag', 'Unused delete', 'unused-delete' );
		$preview = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->item( 'delete', array( $deleted ) ) ) );
		$result  = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
		$this->assertSame( Status::COMPLETED->value, $result['status'] );
		$this->assertNull( get_term( $deleted, 'post_tag' ) );
		$delete_change = $this->journal()->find_for_operation( (int) $preview['id'] )[0];
		$this->assertSame( 'term_deleted', $delete_change['change_type'] );
		$this->assertSame( 0, $delete_change['before_data']['relationship_count'] );
		$this->assertSame( 'deleted', $delete_change['after_data']['result'] );

		$stale   = $this->term( 'post_tag', 'Stale source', 'stale-source' );
		$preview = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->item( 'rename', array( $stale ), array( 'new_name' => 'Never applied' ) ) ) );
		wp_update_term( $stale, 'post_tag', array( 'name' => 'External change' ) );
		try {
			$this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
			$this->fail( 'A stale preview must be rejected.' );
		} catch ( ExecutionException $exception ) {
			$this->assertSame( ExecutionErrorCode::STALE_PREVIEW, $exception->error_code() );
		}
		$this->assertSame( Status::PREVIEWED->value, ( new OperationRepository( $GLOBALS['wpdb'] ) )->find( (int) $preview['id'] )['status'] );
		$this->assertSame( array(), ( new OperationItemRepository( $GLOBALS['wpdb'] ) )->find_for_operation( (int) $preview['id'] ) );
	}

	/** All delete targets are preflighted before the first term is removed. */
	public function test_delete_preflight_rejects_all_targets_without_deleting_any(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$first   = $this->term( 'post_tag', 'Preflight first', 'preflight-first' );
		$second  = $this->term( 'post_tag', 'Preflight second', 'preflight-second' );
		$preview = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->item( 'delete', array( $first, $second ) ) ) );
		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $draft, array( $second ), 'post_tag' );

		try {
			$this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
			$this->fail( 'A changed delete target must reject the complete start.' );
		} catch ( ExecutionException $exception ) {
			$this->assertSame( ExecutionErrorCode::STALE_PREVIEW, $exception->error_code() );
			$this->assertSame( 'Preflight second', $exception->target_name() );
			$this->assertSame( 'relationships_added', $exception->reason() );
		}

		$this->assertInstanceOf( WP_Term::class, get_term( $first, 'post_tag' ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $second, 'post_tag' ) );
		$this->assertSame( array(), ( new OperationItemRepository( $GLOBALS['wpdb'] ) )->find_for_operation( (int) $preview['id'] ) );
	}

	/** Multiple delete items stay bounded, resume pending work, and report a late conflict. */
	public function test_multiple_deletes_are_batched_and_late_use_is_partial_failure(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_ids = array();
		foreach ( range( 1, 11 ) as $index ) {
			$term_ids[] = $this->term( 'post_tag', sprintf( 'Batch delete %02d', $index ), sprintf( 'batch-delete-%02d', $index ) );
		}
		$preview = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->item( 'delete', $term_ids ) ) );
		$first   = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );

		$this->assertSame( Status::RUNNING->value, $first['status'] );
		$this->assertSame( 10, $first['progress']['completed'] );
		$this->assertSame( 1, $first['progress']['pending'] );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $draft, array( $term_ids[10] ), 'post_tag' );
		$final = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );

		$this->assertSame( Status::PARTIAL_FAILED->value, $final['status'] );
		$this->assertSame( 10, $final['progress']['completed'] );
		$this->assertSame( 1, $final['progress']['failed'] );
		$this->assertInstanceOf( WP_Term::class, get_term( $term_ids[10], 'post_tag' ) );
		$changes = $this->journal()->find_for_operation( (int) $preview['id'] );
		$this->assertCount( 10, array_filter( $changes, static fn( array $change ): bool => 'term_deleted' === $change['change_type'] ) );
		$this->assertCount( 1, array_filter( $changes, static fn( array $change ): bool => 'item_failed' === $change['change_type'] ) );
	}

	/** A conflict after one batch produces a truthful partial-failure state. */
	public function test_interrupted_batch_resumes_pending_items_and_reports_partial_failure(): void {
		$user_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source      = $this->term( 'post_tag', 'Interrupted source', 'interrupted-source' );
		$destination = $this->term( 'post_tag', 'Interrupted destination', 'interrupted-destination' );
		$post_ids    = array();
		for ( $index = 0; $index < 11; ++$index ) {
			$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			$post_ids[] = $post_id;
			wp_set_object_terms( $post_id, array( $source ), 'post_tag' );
		}
		$preview = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->merge_item( array( $source ), $destination ) ) );
		$first   = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
		$this->assertSame( Status::RUNNING->value, $first['status'] );
		$this->assertSame( 2, $first['progress']['pending'] );

		wp_update_post(
			array(
				'ID'          => $post_ids[10],
				'post_status' => 'draft',
			)
		);
		$final = $this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );

		$this->assertSame( Status::PARTIAL_FAILED->value, $final['status'] );
		$this->assertSame( 10, $final['progress']['completed'] );
		$this->assertSame( 1, $final['progress']['failed'] );
		$this->assertSame( 1, $final['progress']['skipped'] );
		$this->assertTrue( has_term( $source, 'post_tag', $post_ids[10] ) );
		$this->assertFalse( has_term( $destination, 'post_tag', $post_ids[10] ) );
	}

	/** A changed normalized plan cannot be executed with an older stored hash. */
	public function test_changed_plan_hash_is_rejected_before_items_are_created(): void {
		global $wpdb;

		$user_id                     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term_id                     = $this->term( 'post_tag', 'Hash source', 'hash-source' );
		$preview                     = $this->preview( $user_id, Taxonomy::POST_TAG, array( $this->item( 'rename', array( $term_id ), array( 'new_name' => 'Hash target' ) ) ) );
		$data                        = $preview['requested_data'];
		$data['plan'][0]['new_name'] = 'Tampered target';
		( new OperationRepository( $wpdb ) )->save_preview_context( (int) $preview['id'], (string) $preview['plan_hash'], (string) $preview['state_fingerprint'], $data );

		try {
			$this->execution()->run_batch( (int) $preview['id'], $user_id, Taxonomy::POST_TAG );
			$this->fail( 'A changed plan must invalidate its stored hash.' );
		} catch ( ExecutionException $exception ) {
			$this->assertSame( ExecutionErrorCode::STALE_PREVIEW, $exception->error_code() );
		}
		$this->assertSame( 'Hash source', get_term( $term_id, 'post_tag' )->name );
		$this->assertSame( array(), ( new OperationItemRepository( $wpdb ) )->find_for_operation( (int) $preview['id'] ) );
	}

	/**
	 * Creates a persisted preview for a plan.
	 *
	 * @param int                        $user_id  Owner user ID.
	 * @param Taxonomy                   $taxonomy Plan taxonomy.
	 * @param list<array<string, mixed>> $plan     Raw plan items.
	 * @return array<string, mixed>
	 */
	private function preview( int $user_id, Taxonomy $taxonomy, array $plan ): array {
		global $wpdb;
		$workflow = new PlanWorkflow( new OperationRepository( $wpdb ), new PlanService() );
		foreach ( $plan as $item ) {
			$workflow->add( $user_id, $taxonomy, $item );
		}
		return $workflow->preview( $user_id, $taxonomy );
	}

	/** Returns a fully wired execution workflow. */
	private function execution(): ExecutionWorkflow {
		global $wpdb;
		return new ExecutionWorkflow( new OperationRepository( $wpdb ), new OperationItemRepository( $wpdb ), new OperationLock( $wpdb ), new PlanService(), new ItemExecutor( new ChangeJournalRepository( $wpdb ) ), new DatabaseTransaction( $wpdb ) );
	}

	/** Returns the journal repository. */
	private function journal(): ChangeJournalRepository {
		global $wpdb;
		return new ChangeJournalRepository( $wpdb );
	}

	/**
	 * Creates a term and returns its ID.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $name     Term name.
	 * @param string $slug     Term slug.
	 */
	private function term( string $taxonomy, string $name, string $slug ): int {
		return self::factory()->term->create( compact( 'taxonomy', 'name', 'slug' ) );
	}

	/**
	 * Creates a raw plan item with stable taxonomy identifiers.
	 *
	 * @param string               $action     Action value.
	 * @param array                $source_ids Source term IDs.
	 * @param array<string, mixed> $extra      Action-specific values.
	 * @return array<string, mixed>
	 */
	private function item( string $action, array $source_ids, array $extra = array() ): array {
		$taxonomy_ids = array();
		foreach ( $source_ids as $term_id ) {
			$term                     = get_term( $term_id );
			$taxonomy_ids[ $term_id ] = $term instanceof WP_Term ? (int) $term->term_taxonomy_id : 0;
		}
		return array_merge(
			array(
				'action'        => $action,
				'source_ids'    => $source_ids,
				'source_tt_ids' => $taxonomy_ids,
			),
			$extra
		);
	}

	/**
	 * Creates a raw merge plan item.
	 *
	 * @param array $source_ids    Source term IDs.
	 * @param int   $destination_id Destination term ID.
	 * @return array<string, mixed>
	 */
	private function merge_item( array $source_ids, int $destination_id ): array {
		$destination = get_term( $destination_id );
		return $this->item(
			'merge',
			$source_ids,
			array(
				'destination_id'    => $destination_id,
				'destination_tt_id' => $destination instanceof WP_Term ? (int) $destination->term_taxonomy_id : 0,
			)
		);
	}

	/** Clears plugin persistence tables. */
	private function clear_rows(): void {
		global $wpdb;
		foreach ( array( Tables::changes( $wpdb ), Tables::items( $wpdb ), Tables::operations( $wpdb ) ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated integration tables are intentionally cleared.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}
