<?php
/**
 * Phase 6 history administration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Admin\Page;
use TaxonomyTidy\Admin\HistoryPage;
use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Database\Schema;
use TaxonomyTidy\Infrastructure\Database\Tables;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationItemRepository;
use TaxonomyTidy\Infrastructure\Persistence\ChangeJournalRepository;
use WP_UnitTestCase;

/** Verifies history filtering, localization, ownership, and read security. */
final class HistoryPageTest extends WP_UnitTestCase {
	/**
	 * Preserved query values.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get;

	/**
	 * Preserved posted values.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_post;

	/**
	 * Preserved request method.
	 *
	 * @var string|null
	 */
	private ?string $original_method;

	/** Installs storage and initializes a GET request. */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->clear_rows();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test preserves request state.
		$this->original_get = $_GET;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test preserves request state.
		$this->original_post = $_POST;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test preserves request state.
		$this->original_method     = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;
		$_GET                      = array( 'view' => 'history' );
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/** Restores request state and persistence. */
	public function tear_down(): void {
		$this->clear_rows();
		$_GET  = $this->original_get;
		$_POST = $this->original_post;
		if ( null === $this->original_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->original_method;
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Only started operations owned by the current administrator appear. */
	public function test_history_excludes_drafts_previews_and_other_owners(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$draft      = $repository->create( $user_id, Taxonomy::CATEGORY, $this->plan( 'rename' ) );
		$preview    = $repository->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'delete' ) );
		$repository->transition( $preview, Status::PREVIEWED );
		$running = $repository->create( $user_id, Taxonomy::CATEGORY, $this->plan( 'merge' ) );
		$repository->transition( $running, Status::PREVIEWED );
		$repository->transition( $running, Status::RUNNING );
		$complete = $repository->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'rename' ) );
		$repository->transition( $complete, Status::PREVIEWED );
		$repository->transition( $complete, Status::RUNNING );
		$repository->transition( $complete, Status::COMPLETED );
		$other = $repository->create( $other_id, Taxonomy::CATEGORY, $this->plan( 'delete' ) );
		$repository->transition( $other, Status::PREVIEWED );
		$repository->transition( $other, Status::RUNNING );
		$repository->transition( $other, Status::FAILED );

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '実行中', $output );
		$this->assertStringContainsString( '完了', $output );
		$this->assertStringContainsString( 'role="region" aria-label="操作履歴一覧"', $output );
		$this->assertStringContainsString( '<th scope="col">実行日時</th>', $output );
		$this->assertStringContainsString( '<th scope="col"><span class="screen-reader-text">詳細</span></th>', $output );
		$this->assertStringContainsString( 'history_id=' . $running, $output );
		$this->assertStringContainsString( 'history_id=' . $complete, $output );
		$this->assertStringNotContainsString( 'history_id=' . $draft, $output );
		$this->assertStringNotContainsString( 'history_id=' . $preview, $output );
		$this->assertStringNotContainsString( 'history_id=' . $other, $output );
		$this->assertCount( 2, $repository->history( $user_id, 1, 20 )['items'] );
	}

	/** An operation ID owned by another administrator does not expose its detail. */
	public function test_history_detail_rejects_another_owner(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$other      = $repository->create( $other_id, Taxonomy::CATEGORY, $this->plan( 'rename' ) );
		$repository->transition( $other, Status::PREVIEWED );
		$repository->transition( $other, Status::RUNNING );
		$repository->transition( $other, Status::COMPLETED );
		$_GET['history_id'] = (string) $other;

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '操作履歴または取り消し処理を確認できませんでした。', $output );
		$this->assertStringNotContainsString( '操作 #' . $other . ' の詳細', $output );
	}

	/** An Undo preview POST without a valid nonce creates no child operation. */
	public function test_undo_preview_requires_nonce(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$original   = $repository->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'rename' ) );
		$repository->transition( $original, Status::PREVIEWED );
		$repository->transition( $original, Status::RUNNING );
		$repository->transition( $original, Status::COMPLETED );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'view'                  => 'history',
			'undo_command'          => 'preview_undo',
			'original_operation_id' => (string) $original,
			'taxonomy'              => 'post_tag',
		);

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '操作の有効期限が切れました。', $output );
		$this->assertCount( 1, $repository->history( $user_id, 1, 20 )['items'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Assertion against the isolated custom audit table.
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i', Tables::operations( $GLOBALS['wpdb'] ) ) ) );
	}

	/** A valid Undo preview is modal-only and remains absent from started history. */
	public function test_undo_preview_renders_required_modal_actions(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$term_id    = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'After',
				'slug'     => 'history-undo',
			)
		);
		$repository = new OperationRepository( $GLOBALS['wpdb'] );
		$original   = $repository->create( $user_id, Taxonomy::POST_TAG, $this->plan( 'rename' ) );
		$repository->transition( $original, Status::PREVIEWED );
		$repository->transition( $original, Status::RUNNING );
		$repository->transition( $original, Status::COMPLETED );
		$items   = new OperationItemRepository( $GLOBALS['wpdb'] );
		$item_id = $items->add( $original, 'rename:' . $term_id, Action::RENAME, array() );
		$items->mark_completed( $item_id );
		( new ChangeJournalRepository( $GLOBALS['wpdb'] ) )->record_once( $original, $item_id, 'rename:name', 'name_changed', array( 'name' => 'Before' ), array( 'name' => 'After' ), $term_id );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'view'                   => 'history',
			'undo_command'           => 'preview_undo',
			'original_operation_id'  => (string) $original,
			'taxonomy'               => 'post_tag',
			HistoryPage::NONCE_FIELD => wp_create_nonce( HistoryPage::NONCE_ACTION ),
		);

		ob_start();
		( new Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'role="dialog"', $output );
		$this->assertStringContainsString( '取り消し内容のプレビュー', $output );
		$this->assertStringContainsString( '>キャンセル</button>', $output );
		$this->assertStringContainsString( 'name="undo_command" value="run_undo">元に戻す</button>', $output );
		$this->assertCount( 1, $repository->history( $user_id, 1, 20 )['items'] );
		$this->assertSame( array(), $repository->started_undos( $original ) );
	}

	/**
	 * Returns a minimal stored request used only for history labels.
	 *
	 * @param string $action Operation action.
	 */
	private function plan( string $action ): array {
		return array(
			'plan' => array(
				array(
					'action'  => $action,
					'sources' => array( array( 'term_id' => 1 ) ),
				),
			),
		);
	}

	/** Clears isolated custom persistence tables. */
	private function clear_rows(): void {
		global $wpdb;
		foreach ( array( Tables::changes( $wpdb ), Tables::items( $wpdb ), Tables::operations( $wpdb ) ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated integration tables are intentionally cleared.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}
