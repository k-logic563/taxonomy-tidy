<?php
/**
 * Shared operation-plan tab integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Admin\PlanBoard;
use TaxonomyTidy\Admin\PlanController;
use TaxonomyTidy\Admin\Page;
use TaxonomyTidy\Application\Execution\ExecutionErrorCode;
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

/** Verifies separate storage and one combined administrator execution route. */
final class PlanBoardTest extends WP_UnitTestCase {
	/**
	 * Original POST values.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_post;

	/**
	 * Original request method.
	 *
	 * @var string|null
	 */
	private ?string $original_method;

	/**
	 * Board under test.
	 *
	 * @var PlanBoard
	 */
	private PlanBoard $board;

	/**
	 * Draft workflow.
	 *
	 * @var PlanWorkflow
	 */
	private PlanWorkflow $workflow;

	/**
	 * Stored operations.
	 *
	 * @var OperationRepository
	 */
	private OperationRepository $operations;

	/** Prepares custom tables and two real workflows. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		Schema::install();
		$this->delete_operations();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test setup preserves request state.
		$this->original_post = $_POST;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test setup preserves the request method.
		$this->original_method     = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->operations          = new OperationRepository( $wpdb );
		$plans                     = new PlanService();
		$this->workflow            = new PlanWorkflow( $this->operations, $plans );
		$execution                 = new ExecutionWorkflow( $this->operations, new OperationItemRepository( $wpdb ), new OperationLock( $wpdb ), $plans, new ItemExecutor( new ChangeJournalRepository( $wpdb ) ), new DatabaseTransaction( $wpdb ) );
		$this->board               = new PlanBoard( $this->operations, $this->workflow, $plans, $execution );
	}

	/** Restores globals and clears custom rows. */
	public function tear_down(): void {
		$_POST = $this->original_post;
		if ( null === $this->original_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->original_method;
		}
		wp_set_current_user( 0 );
		$this->delete_operations();
		parent::tear_down();
	}

	/** Category and tag drafts remain separate and visible only to their owner. */
	public function test_two_taxonomy_drafts_are_grouped_and_counted_for_owner(): void {
		$user_id            = $this->login_admin();
		$category           = $this->term( 'category', 'Board category' );
		$tag                = $this->term( 'post_tag', 'Board tag' );
		$category_operation = $this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Renamed category' ) );
		$tag_operation      = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Renamed tag' ) );

		$this->assertNotSame( $category_operation['id'], $tag_operation['id'] );
		$this->assertSame( 'category', $category_operation['taxonomy'] );
		$this->assertSame( 'post_tag', $tag_operation['taxonomy'] );
		$this->assertSame( 2, $this->board->draft_count( $user_id ) );
		ob_start();
		$this->board->render( $this->board->handle() );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Renamed category', $output );
		$this->assertStringContainsString( 'Renamed tag', $output );
		$this->assertStringContainsString( '<h3>カテゴリー</h3>', $output );
		$this->assertStringContainsString( '<h3>タグ</h3>', $output );
		$this->assertSame( 0, substr_count( $output, 'role="dialog"' ) );

		$other_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_id );
		$this->assertSame( 0, $this->board->draft_count( $other_id ) );
		$this->assertSame( array(), $this->board->handle()['operations'] );
	}

	/** One preview dialog starts both existing bounded operations. */
	public function test_combined_preview_and_run_keep_separate_results(): void {
		$user_id  = $this->login_admin();
		$category = $this->term( 'category', 'Run category' );
		$tag      = $this->term( 'post_tag', 'Run tag' );
		$this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Run category changed' ) );
		$this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Run tag changed' ) );

		$this->post( 'preview_all' );
		$preview = $this->board->handle();
		$this->assertSame( array(), $preview['errors'] );
		$this->assertSame( Status::PREVIEWED->value, $preview['operations']['category']['status'] );
		$this->assertSame( Status::PREVIEWED->value, $preview['operations']['post_tag']['status'] );
		$this->assertSame( 0, $this->board->draft_count( $user_id ) );
		$this->assertSame( 'Run category', get_term( $category )->name );
		$this->assertSame( 'Run tag', get_term( $tag )->name );
		ob_start();
		$this->board->render( $preview );
		$output = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $output, 'role="dialog"' ) );
		$this->assertStringContainsString( 'value="run_all"', $output );
		$this->assertSame( 1, substr_count( $output, 'value="run_all"' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$closed                    = $this->board->handle();
		ob_start();
		$this->board->render( $closed );
		$closed_output = (string) ob_get_clean();
		$this->assertFalse( $closed['modal'] );
		$this->assertStringNotContainsString( 'role="dialog"', $closed_output );

		$this->post( 'run_all' );
		$result = $this->board->handle();
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( Status::COMPLETED->value, $result['results']['category']['status'] );
		$this->assertSame( Status::COMPLETED->value, $result['results']['post_tag']['status'] );
		$this->assertSame( 'Run category changed', get_term( $category )->name );
		$this->assertSame( 'Run tag changed', get_term( $tag )->name );
		ob_start();
		$this->board->render( $result );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '処理が完了しました。', $output );
		$this->assertStringNotContainsString( 'role="dialog"', $output );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$reloaded                  = $this->board->handle();
		$this->assertFalse( $reloaded['modal'] );
		$this->assertSame( array(), $reloaded['results'] );
		$this->post_remove( $result['results']['category'], 'category', 0 );
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		$this->assertSame( 'Run category changed', get_term( $category )->name );
	}

	/** A plan for one taxonomy previews and runs without a second operation. */
	public function test_single_taxonomy_plan_runs_alone(): void {
		$user_id = $this->login_admin();
		$tag     = $this->term( 'post_tag', 'Only tag' );
		$this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Only tag changed' ) );
		$this->post( 'preview_all' );
		$preview = $this->board->handle();
		$this->assertSame( array(), $preview['errors'] );
		$this->assertArrayNotHasKey( 'category', $preview['operations'] );
		$this->assertSame( Status::PREVIEWED->value, $preview['operations']['post_tag']['status'] );
		ob_start();
		$this->board->render( $preview );
		$output = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $output, 'role="dialog"' ) );
		$this->assertStringContainsString( '<h3>タグ</h3>', $output );
		$this->assertStringNotContainsString( '<h3>カテゴリー</h3>', $output );
		$this->post( 'run_all' );
		$result = $this->board->handle();
		$this->assertSame( array(), $result['errors'] );
		$this->assertArrayNotHasKey( 'category', $result['results'] );
		$this->assertSame( Status::COMPLETED->value, $result['results']['post_tag']['status'] );
		$this->assertSame( 'Only tag changed', get_term( $tag )->name );
	}

	/** Multiple tag deletions are listed together and start from one modal action. */
	public function test_multiple_tag_deletions_use_one_preview_action_and_one_operation(): void {
		global $wpdb;
		$user_id   = $this->login_admin();
		$first     = $this->term( 'post_tag', 'Unused tag A' );
		$second    = $this->term( 'post_tag', 'Unused tag B' );
		$third     = $this->term( 'post_tag', 'Unused tag C' );
		$operation = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->delete_item( array( $first, $second ) ) );
		$updated   = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->delete_item( array( $third ) ) );

		$this->assertSame( $operation['id'], $updated['id'] );
		$this->assertCount( 2, $updated['requested_data']['plan'] );
		$this->post( 'preview_all' );
		$preview = $this->board->handle();
		ob_start();
		$this->board->render( $preview );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<h4>タグを削除</h4>', $output );
		$this->assertStringContainsString( '削除対象：3件', $output );
		$this->assertStringContainsString( '<li>Unused tag A</li>', $output );
		$this->assertStringContainsString( '<li>Unused tag B</li>', $output );
		$this->assertStringContainsString( '<li>Unused tag C</li>', $output );
		$this->assertSame( 1, substr_count( $output, 'value="run_all"' ) );
		$this->assertSame( 1, substr_count( $output, 'taxonomy-tidy-modal__cancel' ) );
		$this->assertStringNotContainsString( 'taxonomy-tidy-preview-delete__targets"><li>Unused tag A <button', $output );

		$this->post( 'run_all' );
		$result = $this->board->handle();
		$this->assertSame( Status::COMPLETED->value, $result['results']['post_tag']['status'] );
		$this->assertSame( 3, $result['results']['post_tag']['progress']['completed'] );
		$this->assertCount( 3, ( new OperationItemRepository( $wpdb ) )->find_for_operation( (int) $operation['id'] ) );
		$this->assertNull( get_term( $first, 'post_tag' ) );
		$this->assertNull( get_term( $second, 'post_tag' ) );
		$this->assertNull( get_term( $third, 'post_tag' ) );
	}

	/** The category-only path uses the same shared preview and execution action. */
	public function test_category_plan_runs_without_tag_plan(): void {
		$user_id  = $this->login_admin();
		$category = $this->term( 'category', 'Only category' );
		$this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Only category changed' ) );
		$this->post( 'preview_all' );
		$preview = $this->board->handle();
		$this->assertSame( array(), $preview['errors'] );
		$this->assertArrayNotHasKey( 'post_tag', $preview['operations'] );
		$this->assertSame( Status::PREVIEWED->value, $preview['operations']['category']['status'] );
		$this->post( 'run_all' );
		$result = $this->board->handle();
		$this->assertSame( array(), $result['errors'] );
		$this->assertArrayNotHasKey( 'post_tag', $result['results'] );
		$this->assertSame( Status::COMPLETED->value, $result['results']['category']['status'] );
		$this->assertSame( 'Only category changed', get_term( $category )->name );
	}

	/** A stale tag preview prevents both operations from starting. */
	public function test_stale_preview_stops_both_before_any_change(): void {
		$user_id            = $this->login_admin();
		$category           = $this->term( 'category', 'Stable category' );
		$tag                = $this->term( 'post_tag', 'Changing tag' );
		$category_operation = $this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Planned category' ) );
		$tag_operation      = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Planned tag' ) );
		$this->post( 'preview_all' );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		wp_update_term( $tag, 'post_tag', array( 'name' => 'Externally changed tag' ) );
		$this->post( 'run_all' );
		$result = $this->board->handle();
		$this->assertSame( array( 'execution_stale_preview' ), $result['errors'] );
		$this->assertSame( Status::PREVIEWED->value, $this->operations->find( (int) $category_operation['id'] )['status'] );
		$this->assertSame( Status::PREVIEWED->value, $this->operations->find( (int) $tag_operation['id'] )['status'] );
		$this->assertSame( 'Stable category', get_term( $category )->name );
	}

	/** A changed delete target is named in Japanese and prevents every deletion. */
	public function test_delete_preflight_error_names_changed_target(): void {
		$user_id = $this->login_admin();
		$first   = $this->term( 'post_tag', 'Safe delete target' );
		$second  = $this->term( 'post_tag', 'Changed delete target' );
		$this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->delete_item( array( $first, $second ) ) );
		$this->post( 'preview_all' );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $draft, array( $second ), 'post_tag' );

		$this->post( 'run_all' );
		$result = $this->board->handle();
		ob_start();
		$this->board->render( $result );
		$output = (string) ob_get_clean();

		$this->assertSame( array( 'execution_stale_preview' ), $result['errors'] );
		$this->assertStringContainsString( '削除を開始できませんでした。「Changed delete target」は現在、別のオブジェクトで使用されています。', $output );
		$this->assertInstanceOf( WP_Term::class, get_term( $first, 'post_tag' ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $second, 'post_tag' ) );
		$this->assertSame( Status::PREVIEWED->value, $this->operations->find( (int) $result['operations']['post_tag']['id'] )['status'] );
	}

	/** An accepted run with no startable items becomes a persisted failed history entry. */
	public function test_all_unstartable_items_finish_failed_without_changes_or_retry(): void {
		global $wpdb;
		$user_id   = $this->login_admin();
		$first     = $this->term( 'post_tag', 'Failed start A' );
		$second    = $this->term( 'post_tag', 'Failed start B' );
		$operation = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->delete_item( array( $first, $second ) ) );
		$this->post( 'preview_all' );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $draft, array( $first, $second ), 'post_tag' );

		$this->post( 'run_all' );
		$result = $this->board->handle();
		$saved  = $this->operations->find( (int) $operation['id'] );

		$this->assertSame( array( ExecutionErrorCode::NO_STARTABLE_ITEMS ), $result['errors'] );
		$this->assertSame( Status::FAILED->value, $saved['status'] );
		$this->assertNotNull( $saved['started_at'] );
		$this->assertNotNull( $saved['completed_at'] );
		$this->assertSame( ExecutionErrorCode::NO_STARTABLE_ITEMS, $saved['errors']['start_failure']['code'] );
		$this->assertSame( 'relationships_added', $saved['errors']['start_failure']['reason'] );
		$this->assertSame(
			array(
				'total'     => 0,
				'pending'   => 0,
				'completed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
			),
			$saved['result_data']
		);
		$this->assertSame( array(), ( new OperationItemRepository( $wpdb ) )->find_for_operation( (int) $operation['id'] ) );
		$this->assertSame( array(), ( new ChangeJournalRepository( $wpdb ) )->find_for_operation( (int) $operation['id'] ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $first, 'post_tag' ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $second, 'post_tag' ) );
		$this->assertCount( 1, $this->operations->history( $user_id, 1, 20 )['items'] );

		ob_start();
		$this->board->render( $result );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'すべての対象を処理できないため、この操作は失敗として終了しました。', $output );
		$this->assertStringContainsString( '操作計画を作り直し、変更内容をもう一度確認してください。', $output );
		$this->assertStringNotContainsString( '処理が完了しました。', $output );
		$this->assertStringNotContainsString( 'value="continue_all"', $output );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_GET['view']              = 'history';
		$_GET['history_id']        = (string) $operation['id'];
		ob_start();
		( new Page() )->render();
		$history_output = (string) ob_get_clean();
		unset( $_GET['view'], $_GET['history_id'] );
		$this->assertStringContainsString( '<dt>結果</dt><dd>失敗</dd>', $history_output );
		$this->assertStringContainsString( '操作計画を作り直し、変更内容をもう一度確認してください。', $history_output );
	}

	/** Invalid nonce does not turn an unstarted preview into a failed operation. */
	public function test_run_nonce_rejection_keeps_previewed_operation(): void {
		$user_id = $this->login_admin();
		$tag     = $this->term( 'post_tag', 'Nonce preview' );
		$plan    = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Nonce changed' ) );
		$this->post( 'preview_all' );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		$this->post( 'run_all' );
		$_POST[ PlanController::NONCE_FIELD ] = 'invalid';
		$this->assertSame( array( 'invalid_nonce' ), $this->board->handle()['errors'] );
		$this->assertSame( Status::PREVIEWED->value, $this->operations->find( (int) $plan['id'] )['status'] );
	}

	/** Capability rejection does not accept or fail the pending execution request. */
	public function test_run_capability_rejection_keeps_previewed_operation(): void {
		$user_id = $this->login_admin();
		$tag     = $this->term( 'post_tag', 'Capability preview' );
		$plan    = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Capability changed' ) );
		$this->post( 'preview_all' );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->post( 'run_all' );
		$this->assertSame( array( 'permission_denied' ), $this->board->handle()['errors'] );
		$this->assertSame( Status::PREVIEWED->value, $this->operations->find( (int) $plan['id'] )['status'] );
	}

	/** A lock failure in the second taxonomy prevents the first from starting. */
	public function test_combined_start_reserves_every_lock_before_any_change(): void {
		global $wpdb;
		$user_id            = $this->login_admin();
		$category           = $this->term( 'category', 'Lock category' );
		$tag                = $this->term( 'post_tag', 'Lock tag' );
		$category_operation = $this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Changed category' ) );
		$tag_operation      = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Changed tag' ) );
		$this->post( 'preview_all' );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		$lock  = new OperationLock( $wpdb );
		$token = $lock->acquire( (int) $tag_operation['id'] );
		$this->assertIsString( $token );

		$this->post( 'run_all' );
		$result = $this->board->handle();
		$this->assertSame( array( 'execution_locked' ), $result['errors'] );
		$this->assertSame( Status::PREVIEWED->value, $this->operations->find( (int) $category_operation['id'] )['status'] );
		$this->assertSame( Status::PREVIEWED->value, $this->operations->find( (int) $tag_operation['id'] )['status'] );
		$this->assertSame( 'Lock category', get_term( $category )->name );
		$this->assertSame( 'Lock tag', get_term( $tag )->name );
		$this->assertTrue( $lock->release( (int) $tag_operation['id'], $token ) );
	}

	/** A later category conflict leaves the completed tag operation successful. */
	public function test_partial_failure_preserves_other_taxonomy_success(): void {
		global $wpdb;
		$user_id = $this->login_admin();
		for ( $index = 0; $index < 11; $index++ ) {
			$category = $this->term( 'category', sprintf( 'Batch category %02d', $index ) );
			$this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, sprintf( 'Changed category %02d', $index ) ) );
		}
		$tag = $this->term( 'post_tag', 'Independent tag' );
		$this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Completed tag' ) );
		$this->post( 'preview_all' );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		$this->post( 'run_all' );
		$first = $this->board->handle();
		$this->assertSame( Status::RUNNING->value, $first['results']['category']['status'] );
		$this->assertSame( Status::COMPLETED->value, $first['results']['post_tag']['status'] );
		ob_start();
		$this->board->render( $first );
		$progress_output = (string) ob_get_clean();
		$this->assertStringContainsString( '全体：11件、完了：10件、未処理：1件、失敗：0件、スキップ：0件、現在の状態：処理中', $progress_output );
		$this->assertStringContainsString( 'aria-live="polite"', $progress_output );
		$this->post_remove( $first['results']['category'], 'category', 0 );
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$reloaded                  = $this->board->handle();
		$this->assertFalse( $reloaded['modal'] );
		$this->assertSame( array(), $reloaded['results'] );
		ob_start();
		$this->board->render( $reloaded );
		$reloaded_output = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="continue_all"', $reloaded_output );
		$this->assertStringContainsString( '処理を再開', $reloaded_output );
		$this->assertStringNotContainsString( 'role="dialog"', $reloaded_output );
		$this->post( 'discard_all', array( 'confirmed' => '1' ) );
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		$category_id = (int) $first['results']['category']['id'];
		$tag_id      = (int) $first['results']['post_tag']['id'];
		$lock        = new OperationLock( $wpdb );
		$token       = $lock->acquire( $category_id );
		$this->assertIsString( $token );
		$this->post(
			'continue_all',
			array(
				'operation_ids' => array(
					'category' => (string) $category_id,
					'post_tag' => (string) $tag_id,
				),
			)
		);
		$interrupted = $this->board->handle();
		$this->assertFalse( $interrupted['modal'] );
		$this->assertSame( '処理を中断しました。操作計画から再開してください。', $interrupted['notice'] );
		$this->assertSame( Status::RUNNING->value, $interrupted['results']['category']['status'] );
		$this->assertTrue( $lock->release( $category_id, $token ) );
		$pending = ( new OperationItemRepository( $wpdb ) )->find_pending( $category_id, 10 );
		$this->assertCount( 1, $pending );
		wp_update_term( (int) $pending[0]['payload']['term_id'], 'category', array( 'name' => 'Changed by another administrator' ) );
		$this->post(
			'continue_all',
			array(
				'operation_ids' => array(
					'category' => (string) $category_id,
					'post_tag' => (string) $tag_id,
				),
			)
		);
		$final = $this->board->handle();
		$this->assertSame( Status::PARTIAL_FAILED->value, $final['results']['category']['status'] );
		$this->assertSame( Status::COMPLETED->value, $final['results']['post_tag']['status'] );
		$this->assertSame( 'Completed tag', get_term( $tag )->name );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$reloaded                  = $this->board->handle();
		$this->assertFalse( $reloaded['modal'] );
		$this->assertSame( array(), $reloaded['results'] );
		ob_start();
		$this->board->render( $final );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '一部の処理に失敗しました。操作履歴を確認してください。', $output );
		$this->assertStringContainsString( '現在の状態：一部失敗', $output );
		$this->assertStringContainsString( '未処理：0件', $output );
		$this->assertStringContainsString( 'スキップ：0件', $output );
		$this->assertStringNotContainsString( 'role="dialog"', $output );
	}

	/** The board shows one direct delete action and no editing controls. */
	public function test_plan_rows_show_only_accessible_delete_buttons(): void {
		$user_id  = $this->login_admin();
		$category = $this->term( 'category', 'Visible category' );
		$tag      = $this->term( 'post_tag', 'Visible tag' );
		$this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Category renamed' ) );
		$this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Tag renamed' ) );
		ob_start();
		$this->board->render( $this->board->handle() );
		$output = (string) ob_get_clean();
		$this->assertSame( 2, substr_count( $output, 'value="remove_item"' ) );
		$this->assertStringContainsString( '<span class="screen-reader-text">削除</span>', $output );
		$this->assertStringContainsString( '<th scope="col">種別</th>', $output );
		$this->assertStringContainsString( 'role="region" aria-label="カテゴリーの操作計画"', $output );
		$this->assertStringContainsString( 'カテゴリー「Visible category」の名称変更を操作計画から削除', $output );
		$this->assertStringContainsString( 'タグ「Visible tag」の名称変更を操作計画から削除', $output );
		$this->assertStringNotContainsString( '>操作</th>', $output );
		$this->assertStringNotContainsString( '>編集<', $output );
		$this->assertStringNotContainsString( 'value="edit_item"', $output );
		$this->assertStringNotContainsString( 'name="new_name"', $output );
		$this->assertStringNotContainsString( 'taxonomy-tidy-board-destinations', $output );
		$this->post( 'edit_item', array( 'taxonomy' => 'category' ) );
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		$this->assertSame( 2, $this->board->draft_count( $user_id ) );
	}

	/** Removing one draft item preserves all real terms, assignments, and other plans. */
	public function test_removing_one_draft_item_keeps_other_items_and_taxonomy(): void {
		$user_id = $this->login_admin();
		$first   = $this->term( 'category', 'Remove first' );
		$second  = $this->term( 'category', 'Keep second' );
		$tag     = $this->term( 'post_tag', 'Keep tag' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_object_terms( $post_id, $first, 'category' );
		wp_set_object_terms( $post_id, $tag, 'post_tag' );
		$category = $this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $first, 'Wrong name' ) );
		$category = $this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $second, 'Right name' ) );
		$tag_plan = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Right tag' ) );
		$this->post_remove( $category, 'category', 0 );
		$result = $this->board->handle();
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( '操作計画から削除しました。', $result['notice'] );
		$this->assertCount( 1, $result['operations']['category']['requested_data']['plan'] );
		$this->assertSame( $second, $result['operations']['category']['requested_data']['plan'][0]['sources'][0]['term_id'] );
		$this->assertSame( $tag_plan['requested_data']['plan'], $result['operations']['post_tag']['requested_data']['plan'] );
		$this->assertSame( 2, $this->board->draft_count( $user_id ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $first ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $tag ) );
		$this->assertTrue( has_term( $first, 'category', $post_id ) );
		$this->assertTrue( has_term( $tag, 'post_tag', $post_id ) );
		$this->assertNull( $result['operations']['category']['plan_hash'] );
		$this->assertNull( $result['operations']['category']['state_fingerprint'] );
		ob_start();
		$this->board->render( $result );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '操作計画から削除しました。', $output );
		$this->post_remove( $tag_plan, 'post_tag', 0 );
		$after_tag_removal = $this->board->handle();
		$this->assertSame( array(), $after_tag_removal['errors'] );
		$this->assertSame( $second, $after_tag_removal['operations']['category']['requested_data']['plan'][0]['sources'][0]['term_id'] );
		$this->assertSame( 1, $this->board->draft_count( $user_id ) );
		$this->assertTrue( has_term( $tag, 'post_tag', $post_id ) );
	}

	/** Empty drafts are retained but excluded from the badge and shown as empty. */
	public function test_last_item_removal_keeps_empty_draft_without_counting_it(): void {
		$user_id       = $this->login_admin();
		$category      = $this->term( 'category', 'Last category' );
		$tag           = $this->term( 'post_tag', 'Last tag' );
		$category_plan = $this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Changed category' ) );
		$tag_plan      = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Changed tag' ) );
		$this->post_remove( $category_plan, 'category', 0 );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		$this->assertSame( 1, $this->board->draft_count( $user_id ) );
		$this->post_remove( $tag_plan, 'post_tag', 0 );
		$result = $this->board->handle();
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 0, $this->board->draft_count( $user_id ) );
		$this->assertSame( Status::DRAFT->value, $this->operations->find( (int) $category_plan['id'] )['status'] );
		$this->assertSame( array(), $this->operations->find( (int) $category_plan['id'] )['requested_data']['plan'] );
		$this->assertSame( array(), $this->operations->find( (int) $tag_plan['id'] )['requested_data']['plan'] );
		ob_start();
		$this->board->render( $result );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '操作計画はまだありません。', $output );
		$this->assertStringContainsString( 'カテゴリーまたはタグを選択し、処理パネルから計画へ追加してください。', $output );
	}

	/** An empty retained category draft does not block the remaining tag plan. */
	public function test_empty_draft_does_not_block_other_taxonomy_execution(): void {
		$user_id       = $this->login_admin();
		$category      = $this->term( 'category', 'Removed category plan' );
		$tag           = $this->term( 'post_tag', 'Remaining tag plan' );
		$category_plan = $this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Unused change' ) );
		$this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Applied change' ) );
		$this->post_remove( $category_plan, 'category', 0 );
		$after_delete = $this->board->handle();
		$this->assertSame( array(), $after_delete['errors'] );
		$this->assertArrayNotHasKey( 'category', $after_delete['operations'] );
		$this->assertSame( 1, $this->board->draft_count( $user_id ) );
		$this->post( 'preview_all' );
		$preview = $this->board->handle();
		$this->assertSame( array(), $preview['errors'] );
		$this->assertArrayNotHasKey( 'category', $preview['operations'] );
		$this->post( 'run_all' );
		$result = $this->board->handle();
		$this->assertSame( array(), $result['errors'] );
		$this->assertArrayNotHasKey( 'category', $result['results'] );
		$this->assertSame( Status::COMPLETED->value, $result['results']['post_tag']['status'] );
		$this->assertSame( 'Removed category plan', get_term( $category )->name );
		$this->assertSame( 'Applied change', get_term( $tag )->name );
	}

	/** Deletion checks ownership, nonce, draft status, item index, and plan hash. */
	public function test_item_deletion_rejects_invalid_requests_and_previewed_plans(): void {
		$user_id   = $this->login_admin();
		$tag       = $this->term( 'post_tag', 'Protected tag' );
		$operation = $this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Planned tag' ) );
		$this->post_remove( $operation, 'post_tag', 0 );
		$_POST[ PlanController::NONCE_FIELD ] = 'invalid';
		$this->assertSame( array( 'invalid_nonce' ), $this->board->handle()['errors'] );
		$this->post_remove( $operation, 'post_tag', 2 );
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		$this->post_remove( $operation, 'post_tag', 0 );
		$_POST['expected_plan_hash'] = 'stale';
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		$this->post_remove( $operation, 'post_tag', 0 );
		$_POST['operation_id'] = '999999';
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		$other_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_id );
		$this->post_remove( $operation, 'post_tag', 0 );
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->post_remove( $operation, 'post_tag', 0 );
		$this->assertSame( array( 'permission_denied' ), $this->board->handle()['errors'] );
		wp_set_current_user( $user_id );
		$this->assertSame( 1, $this->board->draft_count( $user_id ) );
		$this->post( 'preview_all' );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		$this->post_remove( $operation, 'post_tag', 0 );
		$previewed = $this->board->handle();
		$this->assertSame( array( 'plan_invalid' ), $previewed['errors'] );
		$this->assertSame( Status::PREVIEWED->value, $this->operations->find( (int) $operation['id'] )['status'] );
		$this->assertInstanceOf( WP_Term::class, get_term( $tag ) );
		ob_start();
		$this->board->render( $previewed );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '操作計画が変更されたか、この操作を行えない状態です。', $output );
		$this->assertStringNotContainsString( 'value="remove_item"', $output );
	}

	/** Reopening a preview and deleting an item clears its old execution context. */
	public function test_deletion_after_revising_preview_invalidates_old_execution(): void {
		$user_id = $this->login_admin();
		$first   = $this->term( 'category', 'Preview first' );
		$second  = $this->term( 'category', 'Preview second' );
		$this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $first, 'First planned' ) );
		$this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $second, 'Second planned' ) );
		$this->post( 'preview_all' );
		$previewed = $this->board->handle();
		$this->assertSame( array(), $previewed['errors'] );
		$this->assertNotNull( $previewed['operations']['category']['plan_hash'] );
		$draft = $this->workflow->revise( $user_id, Taxonomy::CATEGORY );
		$this->post_remove( $draft, 'category', 0 );
		$result = $this->board->handle();
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( Status::DRAFT->value, $result['operations']['category']['status'] );
		$this->assertNull( $result['operations']['category']['plan_hash'] );
		$this->assertNull( $result['operations']['category']['state_fingerprint'] );
		$this->assertArrayNotHasKey( 'preview', $result['operations']['category']['requested_data'] );
		$this->post( 'run_all' );
		$this->assertSame( array( 'execution_invalid_operation' ), $this->board->handle()['errors'] );
		$this->assertSame( 'Preview first', get_term( $first )->name );
		$this->assertSame( 'Preview second', get_term( $second )->name );
	}

	/** Discard still requires confirmation and clears both taxonomies. */
	public function test_discard_all_keeps_its_confirmation_gate(): void {
		$user_id  = $this->login_admin();
		$category = $this->term( 'category', 'Discard category' );
		$tag      = $this->term( 'post_tag', 'Discard tag' );
		$this->workflow->add( $user_id, Taxonomy::CATEGORY, $this->rename_item( $category, 'Change category' ) );
		$this->workflow->add( $user_id, Taxonomy::POST_TAG, $this->rename_item( $tag, 'Change tag' ) );
		$this->post( 'discard_all' );
		$this->assertSame( array( 'plan_invalid' ), $this->board->handle()['errors'] );
		$this->assertSame( 2, $this->board->draft_count( $user_id ) );
		$this->post( 'discard_all', array( 'confirmed' => '1' ) );
		$this->assertSame( array(), $this->board->handle()['errors'] );
		$this->assertSame( 0, $this->board->draft_count( $user_id ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $category ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $tag ) );
	}

	/** Logs in and returns an administrator ID. */
	private function login_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Creates a term.
	 *
	 * @param string $taxonomy Supported taxonomy.
	 * @param string $name Term name.
	 */
	private function term( string $taxonomy, string $name ): int {
		return self::factory()->term->create(
			array(
				'taxonomy' => $taxonomy,
				'name'     => $name,
			)
		);
	}

	/**
	 * Creates a raw rename item with its stable taxonomy ID.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $new_name New term name.
	 * @return array<string, mixed>
	 */
	private function rename_item( int $term_id, string $new_name ): array {
		$term = get_term( $term_id );
		$this->assertInstanceOf( WP_Term::class, $term );
		return array(
			'action'        => 'rename',
			'source_ids'    => array( $term_id ),
			'source_tt_ids' => array( $term_id => (int) $term->term_taxonomy_id ),
			'new_name'      => $new_name,
		);
	}

	/**
	 * Creates a raw delete item with stable taxonomy IDs.
	 *
	 * @param array<int> $term_ids Term IDs.
	 * @return array<string, mixed>
	 */
	private function delete_item( array $term_ids ): array {
		$taxonomy_ids = array();
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			$this->assertInstanceOf( WP_Term::class, $term );
			$taxonomy_ids[ $term_id ] = (int) $term->term_taxonomy_id;
		}
		return array(
			'action'        => 'delete',
			'source_ids'    => $term_ids,
			'source_tt_ids' => $taxonomy_ids,
		);
	}

	/**
	 * Posts one board command with a valid nonce.
	 *
	 * @param string               $command Board action.
	 * @param array<string, mixed> $extra   Additional fields.
	 */
	private function post( string $command, array $extra = array() ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge(
			array(
				'plan_command'              => $command,
				PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
			),
			$extra
		);
	}

	/**
	 * Posts a row deletion using the currently displayed draft identity.
	 *
	 * @param array<string, mixed> $operation Draft operation.
	 * @param string               $taxonomy  Draft taxonomy.
	 * @param int                  $index     Row index.
	 */
	private function post_remove( array $operation, string $taxonomy, int $index ): void {
		$this->post(
			'remove_item',
			array(
				'taxonomy'           => $taxonomy,
				'operation_id'       => (string) $operation['id'],
				'item_index'         => (string) $index,
				'expected_plan_hash' => ( new PlanService() )->plan_hash( $operation['requested_data']['plan'] ),
			)
		);
	}

	/** Clears custom operation tables between tests. */
	private function delete_operations(): void {
		global $wpdb;
		foreach ( array( Tables::changes( $wpdb ), Tables::items( $wpdb ), Tables::operations( $wpdb ) ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test tables are cleared in isolation.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}
