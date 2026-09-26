<?php
/**
 * Administration-script regression checks.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use WP_UnitTestCase;

/** Keeps native destination selection synchronized with selected sources. */
final class AdminScriptTest extends WP_UnitTestCase {
	/** Every selected source is hidden and disabled in the destination select. */
	public function test_destination_options_are_re_evaluated_by_stable_id(): void {
		$script = $this->script();
		$this->assertStringContainsString( 'destination.selectedOptions[ 0 ]?.dataset.termKey', $script );
		$this->assertStringContainsString( 'currentSourceIds.has( option.dataset.termKey )', $script );
		$this->assertStringContainsString( 'option.disabled = excluded', $script );
		$this->assertStringContainsString( 'option.hidden = excluded', $script );
	}

	/** A destination newly selected as a source is cleared with the required notice. */
	public function test_selected_destination_is_cleared_after_source_change(): void {
		$script = $this->script();
		$this->assertStringContainsString( 'currentSourceIds.has( selectedId )', $script );
		$this->assertStringContainsString( "destination.value = ''", $script );
		$this->assertStringContainsString( 'destinationNotice.textContent = destinationGroup.dataset.cleared', $script );
		$this->assertStringNotContainsString( 'taxonomy_tidy_search_destinations', $script );
	}

	/** Asynchronous actions expose a non-color loading state to assistive technology. */
	public function test_async_actions_publish_loading_state(): void {
		$script = $this->script();
		$this->assertStringContainsString( "submitter.classList.add( 'is-loading' )", $script );
		$this->assertStringContainsString( "submitter.setAttribute( 'aria-busy', 'true' )", $script );
		$this->assertStringContainsString( "submitter.removeAttribute( 'aria-busy' )", $script );
	}

	/** Undo batches continue automatically with bounded network retry and terminal guards. */
	public function test_history_script_automates_undo_batches_safely(): void {
		$script = $this->history_script();
		$this->assertStringContainsString( 'const maxAttempts = 3', $script );
		$this->assertStringContainsString( "progress.has_more && progress.status === 'undoing'", $script );
		$this->assertStringContainsString( "[ 'undone', 'undo_partial_failed', 'failed' ].includes", $script );
		$this->assertStringContainsString( "data.set( 'action', 'taxonomy_tidy_undo_batch' )", $script );
		$this->assertStringContainsString( 'setModalLocked( true )', $script );
		$this->assertStringContainsString( "button.setAttribute( 'aria-busy', 'true' )", $script );
		$this->assertStringContainsString( 'progress.processed <= previousProcessed', $script );
		$this->assertStringContainsString( 'showResult( progress )', $script );
		$this->assertStringContainsString( 'footer.replaceChildren( close )', $script );
		$this->assertStringContainsString( "heading.textContent = strings.resultTitle || '取り消し結果'", $script );
		$this->assertStringContainsString( "[ 'undone', 'undo_partial_failed', 'failed' ].includes( progress.status )", $script );
		$this->assertStringContainsString( "document.querySelectorAll( '.taxonomy-tidy-undo-preview' )", $script );
		$this->assertStringContainsString( "button.dataset.submitting === '1'", $script );
		$this->assertStringContainsString( 'globalThis.setTimeout', $script );
		$this->assertStringContainsString( "document.querySelector( '.taxonomy-tidy-history-error' )", $script );
		$this->assertStringContainsString( "if ( error?.name === 'DataError' )", $script );
		$this->assertStringContainsString( "showStopped( error?.name === 'DataError' ? error.message", $script );
	}

	/** Undo Ajax distinguishes an invalid nonce from retryable transport failures. */
	public function test_undo_ajax_returns_non_retryable_session_guidance_for_invalid_nonce(): void {
		$page = $this->page_source();
		$this->assertStringContainsString( 'wp_verify_nonce( $nonce, HistoryPage::NONCE_ACTION )', $page );
		$this->assertStringContainsString( 'セッションまたは認証情報が無効になりました。ページを再読み込みし、必要に応じて再ログインしてから操作を再開してください。', $page );
		$this->assertStringContainsString( "'retryable' => false", $page );
		$this->assertStringContainsString( "\t\t\t\t403\n\t\t\t);", $page );
		$this->assertStringContainsString( 'UndoErrorCode::STALE_PREVIEW, UndoErrorCode::LOCKED', $page );
	}

	/** Plan-modal focus resolves the current trigger after Ajax replaces the board. */
	public function test_plan_modal_restores_focus_to_the_current_dom(): void {
		$script = $this->plan_board_script();
		$this->assertStringContainsString( 'openerSelector = selectorForOpener( trigger )', $script );
		$this->assertStringContainsString( 'document.querySelector( openerSelector )', $script );
		$this->assertStringContainsString( 'opener?.isConnected ? opener : null', $script );
		$this->assertStringContainsString( '#taxonomy-tidy-board-content h2, .nav-tab[href*="view=plan"]', $script );
		$this->assertStringContainsString( 'restoreOpenerFocus()', $script );
	}

	/** History logs are fetched only after expansion and use accessible state. */
	public function test_history_script_lazily_expands_logs(): void {
		$script = $this->history_script();
		$this->assertStringContainsString( "data.set( 'action', 'taxonomy_tidy_history_logs' )", $script );
		$this->assertStringContainsString( "button.setAttribute( 'aria-expanded', 'true' )", $script );
		$this->assertStringContainsString( "button.textContent = strings.collapse || '閉じる'", $script );
		$this->assertStringContainsString( 'page <= totalPages', $script );
	}

	/** Returns the administration script source. */
	private function script(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local version-controlled test subject.
		$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' );
		$this->assertIsString( $script );
		return $script;
	}

	/** Returns the history script source. */
	private function history_script(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local version-controlled test subject.
		$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/history.js' );
		$this->assertIsString( $script );
		return $script;
	}

	/** Returns the operation-plan board script source. */
	private function plan_board_script(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local version-controlled test subject.
		$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/plan-board.js' );
		$this->assertIsString( $script );
		return $script;
	}

	/** Returns the Ajax controller source. */
	private function page_source(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local version-controlled test subject.
		$page = file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Page.php' );
		$this->assertIsString( $page );
		return $page;
	}
}
