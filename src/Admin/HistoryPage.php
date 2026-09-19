<?php
/**
 * Operation history and Undo administration UI.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

use TaxonomyTidy\Application\Undo\UndoErrorCode;
use TaxonomyTidy\Application\Undo\UndoException;
use TaxonomyTidy\Application\Undo\UndoPlanner;
use TaxonomyTidy\Application\Undo\UndoWorkflow;
use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\ChangeJournalRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationItemRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;

/** Handles the owner-scoped audit list, detail, and Undo commands. */
final class HistoryPage {
	public const NONCE_ACTION = 'taxonomy_tidy_undo';
	public const NONCE_FIELD  = 'taxonomy_tidy_undo_nonce';

	/**
	 * Creates the owner-scoped history screen.
	 *
	 * @param OperationRepository     $operations Operation storage.
	 * @param OperationItemRepository $items      Fixed item storage.
	 * @param ChangeJournalRepository $journal    Actual changes.
	 * @param UndoPlanner             $planner    Undo assessor.
	 * @param UndoWorkflow            $workflow   Bounded Undo workflow.
	 */
	public function __construct(
		private readonly OperationRepository $operations,
		private readonly OperationItemRepository $items,
		private readonly ChangeJournalRepository $journal,
		private readonly UndoPlanner $planner,
		private readonly UndoWorkflow $workflow
	) {
	}

	/** Renders and handles the complete history tab. */
	public function render(): void {
		$user_id = get_current_user_id();
		$error   = null;
		$modal   = null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Used only for a fixed HTTP verb comparison.
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( ! Access::current_user_can_access() ) {
				$error = UndoErrorCode::INVALID_OPERATION;
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Sanitized and verified immediately below.
				$nonce = is_string( $_POST[ self::NONCE_FIELD ] ?? null ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
				if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
					$error = 'invalid_nonce';
				} else {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above; fixed command and IDs sanitized below.
					$request = wp_unslash( $_POST );
					$command = is_string( $request['undo_command'] ?? null ) ? sanitize_key( $request['undo_command'] ) : '';
					try {
						if ( 'preview_undo' === $command ) {
							$original_id = absint( $request['original_operation_id'] ?? 0 );
							$this->assert_taxonomy( $original_id, $user_id, $request );
							$modal = $this->planner->preview( $original_id, $user_id );
						} elseif ( in_array( $command, array( 'run_undo', 'continue_undo' ), true ) ) {
							$undo_id = absint( $request['undo_operation_id'] ?? 0 );
							$this->assert_taxonomy( $undo_id, $user_id, $request );
							$modal = $this->workflow->run_batch( $undo_id, $user_id );
						} else {
							$error = UndoErrorCode::INVALID_OPERATION;
						}
					} catch ( UndoException $exception ) {
						$error = $exception->error_code();
					} catch ( \Throwable $exception ) {
						$this->log_internal_error( $exception );
						$error = UndoErrorCode::JOURNAL_FAILED;
					}
				}
			}
		}

		$requested_page = $this->query_id( 'history_page' );
		$page           = 0 === $requested_page ? 1 : $requested_page;
		$history        = $this->operations->history( $user_id, $page, 20 );
		$detail         = null;
		$detail_id      = $this->query_id( 'history_id' );
		if ( 0 < $detail_id ) {
			$candidate = $this->operations->find_owned( $detail_id, $user_id );
			if ( null !== $candidate && null !== $candidate['started_at'] ) {
				$detail = $candidate;
			} else {
				$error = UndoErrorCode::INVALID_OPERATION;
			}
		}
		?>
		<div id="taxonomy-tidy-history-content">
		<h2><?php echo esc_html__( '操作履歴', 'taxonomy-tidy' ); ?></h2>
		<?php if ( null !== $error ) : ?>
			<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( $this->error_label( $error ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( null !== $detail ) : ?>
			<?php $this->render_detail( $detail, $user_id ); ?>
		<?php else : ?>
			<?php $this->render_list( $history, $user_id ); ?>
		<?php endif; ?>
		</div>
		<?php
		if ( null !== $modal ) {
			$this->render_modal( $modal );
		}
	}

	/**
	 * Requires an owned operation in the posted taxonomy.
	 *
	 * @param int                  $operation_id Operation ID.
	 * @param int                  $user_id      Current administrator ID.
	 * @param array<string, mixed> $request      Verified request values.
	 * @throws UndoException When identity or taxonomy does not match.
	 */
	private function assert_taxonomy( int $operation_id, int $user_id, array $request ): void {
		$operation = $this->operations->find_owned( $operation_id, $user_id );
		$taxonomy  = Taxonomy::tryFrom( sanitize_key( (string) ( $request['taxonomy'] ?? '' ) ) );
		if ( null === $operation || null === $taxonomy || $taxonomy->value !== $operation['taxonomy'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable internal code only.
			throw new UndoException( UndoErrorCode::INVALID_OPERATION );
		}
	}

	/**
	 * Renders the paginated history table.
	 *
	 * @param array<string, mixed> $history Page of operations.
	 * @param int                  $user_id Current administrator ID.
	 */
	private function render_list( array $history, int $user_id ): void {
		if ( array() === $history['items'] ) {
			?>
			<p><?php echo esc_html__( '実行済みの操作はありません。', 'taxonomy-tidy' ); ?></p>
			<?php
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped taxonomy-tidy-history-table">
			<thead><tr><th><?php echo esc_html__( '実行日時', 'taxonomy-tidy' ); ?></th><th><?php echo esc_html__( '種別', 'taxonomy-tidy' ); ?></th><th><?php echo esc_html__( '処理内容', 'taxonomy-tidy' ); ?></th><th><?php echo esc_html__( '対象件数', 'taxonomy-tidy' ); ?></th><th><?php echo esc_html__( '変更件数', 'taxonomy-tidy' ); ?></th><th><?php echo esc_html__( '結果', 'taxonomy-tidy' ); ?></th><th><?php echo esc_html__( '取り消し', 'taxonomy-tidy' ); ?></th><th><span class="screen-reader-text"><?php echo esc_html__( '詳細', 'taxonomy-tidy' ); ?></span></th></tr></thead>
			<tbody>
			<?php foreach ( $history['items'] as $operation ) : ?>
				<?php $assessment = null === $operation['parent_operation_id'] ? $this->planner->assess( (int) $operation['id'], $user_id ) : null; ?>
				<tr><td><?php echo esc_html( $this->date_label( (string) $operation['started_at'] ) ); ?></td><td><?php echo esc_html( $this->taxonomy_label( (string) $operation['taxonomy'] ) ); ?></td><td><?php echo esc_html( $this->action_summary( $operation ) ); ?></td><td><?php echo esc_html( (string) $this->target_count( $operation ) ); ?></td><td><?php echo esc_html( (string) $this->change_count( (int) $operation['id'] ) ); ?></td><td><?php echo esc_html( $this->status_label( (string) $operation['status'] ) ); ?></td><td><?php echo esc_html( null === $assessment ? $this->undo_result_label( $operation ) : $this->availability_label( (string) $assessment['availability'] ) ); ?></td><td><a href="<?php echo esc_url( $this->history_url( array( 'history_id' => (int) $operation['id'] ) ) ); ?>"><?php echo esc_html__( '詳細', 'taxonomy-tidy' ); ?></a></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php $this->render_pagination( $history ); ?>
		<?php
	}

	/**
	 * Renders a safe human-readable operation detail.
	 *
	 * @param array<string, mixed> $operation Owned started operation.
	 * @param int                  $user_id   Current administrator ID.
	 */
	private function render_detail( array $operation, int $user_id ): void {
		$changes    = $this->journal->find_for_operation( (int) $operation['id'] );
		$assessment = null === $operation['parent_operation_id'] ? $this->planner->assess( (int) $operation['id'], $user_id ) : null;
		$result     = is_array( $operation['result_data'] ) ? $operation['result_data'] : array();
		?>
		<p><a href="<?php echo esc_url( $this->history_url() ); ?>">&larr; <?php echo esc_html__( '操作履歴へ戻る', 'taxonomy-tidy' ); ?></a></p>
		<h3><?php echo esc_html( sprintf( /* translators: %d: operation ID. */ __( '操作 #%d の詳細', 'taxonomy-tidy' ), (int) $operation['id'] ) ); ?></h3>
		<dl class="taxonomy-tidy-history-detail">
			<dt><?php echo esc_html__( '実行日時', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->date_label( (string) $operation['started_at'] ) ); ?></dd>
			<dt><?php echo esc_html__( '完了日時', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->date_label( (string) ( $operation['completed_at'] ?? '' ) ) ); ?></dd>
			<dt><?php echo esc_html__( '種別', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->taxonomy_label( (string) $operation['taxonomy'] ) ); ?></dd>
			<dt><?php echo esc_html__( '実行した処理', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->action_summary( $operation ) ); ?></dd>
			<dt><?php echo esc_html__( '対象となった分類', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->target_label( $operation ) ); ?></dd>
			<dt><?php echo esc_html__( '結果', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->status_label( (string) $operation['status'] ) ); ?></dd>
			<dt><?php echo esc_html__( '成功件数', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( (string) (int) ( $result['completed'] ?? 0 ) ); ?></dd>
			<dt><?php echo esc_html__( '失敗件数', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( (string) (int) ( $result['failed'] ?? 0 ) ); ?></dd>
			<dt><?php echo esc_html__( '対象となった公開済み投稿数', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( (string) $this->affected_post_count( $changes ) ); ?></dd>
			<dt><?php echo esc_html__( '保持された分類', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( (string) $this->retained_count( $changes ) ); ?></dd>
			<dt><?php echo esc_html__( '警告', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->message_summary( $operation['warnings'], true ) ); ?></dd>
			<dt><?php echo esc_html__( 'エラー', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->message_summary( $operation['errors'], false ) ); ?></dd>
			<dt><?php echo esc_html__( '取り消し状態', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( $this->undo_state_label( $operation ) ); ?></dd>
			<?php if ( null !== $operation['parent_operation_id'] && in_array( $operation['status'], array( Status::UNDO_PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) : ?>
				<dt><?php echo esc_html__( '残っている項目', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: remaining Undo item count. */ __( '%d件', 'taxonomy-tidy' ), $this->remaining_count( $operation ) ) ); ?></dd>
				<dt><?php echo esc_html__( '再試行', 'taxonomy-tidy' ); ?></dt><dd><?php echo esc_html__( '自動再試行には対応していません。現在状態を確認してください。', 'taxonomy-tidy' ); ?></dd>
			<?php endif; ?>
			<?php
			if ( null !== $operation['parent_operation_id'] ) :
				?>
				<dt><?php echo esc_html__( '取り消し対象', 'taxonomy-tidy' ); ?></dt><dd><a href="<?php echo esc_url( $this->history_url( array( 'history_id' => (int) $operation['parent_operation_id'] ) ) ); ?>">#<?php echo esc_html( (string) $operation['parent_operation_id'] ); ?></a></dd><?php endif; ?>
		</dl>
		<?php $this->render_change_summary( $changes ); ?>
		<?php if ( null !== $assessment ) : ?>
			<h3><?php echo esc_html__( '取り消し可否', 'taxonomy-tidy' ); ?></h3>
			<p><strong><?php echo esc_html( $this->availability_label( (string) $assessment['availability'] ) ); ?></strong><br><?php echo esc_html( $this->reason_label( (string) $assessment['reason'] ) ); ?></p>
			<?php if ( 'none' !== $assessment['availability'] ) : ?>
			<form method="post"><input type="hidden" name="view" value="history"><input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"><input type="hidden" name="original_operation_id" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( (string) $operation['taxonomy'] ); ?>"><button type="submit" class="button button-secondary taxonomy-tidy-undo-preview" name="undo_command" value="preview_undo"><?php echo esc_html__( '変更を元に戻す', 'taxonomy-tidy' ); ?></button></form>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders only human-readable actual changes.
	 *
	 * @param list<array<string, mixed>> $changes Journal entries.
	 */
	private function render_change_summary( array $changes ): void {
		$visible = array_filter( $changes, fn( array $change ): bool => $this->is_actual_change( (string) $change['change_type'] ) );
		if ( array() === $visible ) {
			return;
		}
		?>
		<h3><?php echo esc_html__( '変更前と変更後', 'taxonomy-tidy' ); ?></h3><ul class="taxonomy-tidy-change-summary">
		<?php
		foreach ( $visible as $change ) {
			?>
			<li><?php echo esc_html( $this->change_label( $change ) ); ?></li>
			<?php
		}
		?>
		</ul>
		<?php
	}

	/**
	 * Renders the Undo preview or progress dialog.
	 *
	 * @param array<string, mixed> $undo Undo operation.
	 */
	private function render_modal( array $undo ): void {
		$preview    = is_array( $undo['requested_data']['preview'] ?? null ) ? $undo['requested_data']['preview'] : array();
		$running    = Status::UNDOING->value === $undo['status'];
		$previewing = Status::UNDO_PREVIEWED->value === $undo['status'];
		?>
		<div class="taxonomy-tidy-modal taxonomy-tidy-history-modal" data-auto-open="1" hidden><div class="taxonomy-tidy-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="taxonomy-tidy-undo-heading" tabindex="-1">
		<header class="taxonomy-tidy-modal__header"><h2 id="taxonomy-tidy-undo-heading"><?php echo esc_html( $previewing ? __( '取り消し内容のプレビュー', 'taxonomy-tidy' ) : __( '取り消し結果', 'taxonomy-tidy' ) ); ?></h2><button type="button" class="taxonomy-tidy-modal__close" aria-label="<?php echo esc_attr__( '閉じる', 'taxonomy-tidy' ); ?>" <?php disabled( $running ); ?>>&times;</button></header>
		<div class="taxonomy-tidy-modal__body" aria-live="polite">
		<?php if ( $previewing ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %d: original operation ID. */ __( '元に戻す操作：#%d', 'taxonomy-tidy' ), (int) $undo['parent_operation_id'] ) ); ?></p>
			<p><?php echo esc_html( sprintf( /* translators: %s: taxonomy label. */ __( '対象：%s', 'taxonomy-tidy' ), $this->taxonomy_label( (string) $undo['taxonomy'] ) ) ); ?></p>
			<p><strong><?php echo esc_html( $this->availability_label( (string) $preview['availability'] ) ); ?></strong></p>
			<p><?php echo esc_html( sprintf( /* translators: 1: restored terms, 2: restored assignments, 3: removed assignments. */ __( '復元される分類 %1$d件、復元される投稿割り当て %2$d件、削除される割り当て %3$d件', 'taxonomy-tidy' ), (int) $preview['restore_terms'], (int) $preview['restore_assignments'], (int) $preview['remove_assignments'] ) ); ?></p>
			<?php
			if ( array() !== (array) $preview['conflicts'] ) :
				?>
				<p class="taxonomy-tidy-message taxonomy-tidy-message--warning"><?php echo esc_html( sprintf( /* translators: %d: conflict count. */ __( '競合により元に戻せない項目が%d件あります。', 'taxonomy-tidy' ), count( $preview['conflicts'] ) ) ); ?></p>
				<ul class="taxonomy-tidy-conflicts">
				<?php foreach ( $preview['conflicts'] as $conflict ) : ?>
					<li><strong><?php echo esc_html( $this->conflict_target_label( (string) $conflict['type'] ) ); ?></strong><br><?php echo esc_html( $this->conflict_plan_label( (string) $conflict['type'] ) ); ?><br><?php echo esc_html( $this->conflict_current_label( (string) $conflict['reason'] ) ); ?></li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php else : ?>
			<p><strong><?php echo esc_html( $this->status_label( (string) $undo['status'] ) ); ?></strong></p>
			<?php $progress = is_array( $undo['progress'] ?? null ) ? $undo['progress'] : (array) $undo['result_data']; ?>
			<p><?php echo esc_html( sprintf( /* translators: 1: completed count, 2: total count, 3: failed count. */ __( '完了 %1$d / 全体 %2$d、失敗 %3$d', 'taxonomy-tidy' ), (int) ( $progress['completed'] ?? 0 ), (int) ( $progress['total'] ?? 0 ), (int) ( $progress['failed'] ?? 0 ) ) ); ?></p>
		<?php endif; ?>
		</div>
		<footer class="taxonomy-tidy-modal__footer"><button type="button" class="button taxonomy-tidy-modal__cancel" <?php disabled( $running ); ?>><?php echo esc_html__( 'キャンセル', 'taxonomy-tidy' ); ?></button>
		<form method="post"><input type="hidden" name="view" value="history"><input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"><input type="hidden" name="undo_operation_id" value="<?php echo esc_attr( (string) $undo['id'] ); ?>"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( (string) $undo['taxonomy'] ); ?>">
		<?php
		if ( $previewing ) :
			?>
			<button type="submit" class="button button-primary" name="undo_command" value="run_undo"><?php echo esc_html__( '元に戻す', 'taxonomy-tidy' ); ?></button>
			<?php
			elseif ( $running ) :
				?>
			<button type="submit" class="button button-primary" name="undo_command" value="continue_undo"><?php echo esc_html__( '次の処理を続ける', 'taxonomy-tidy' ); ?></button><?php endif; ?>
		</form></footer></div></div>
		<?php
	}

	/**
	 * Renders numerical server-side pagination.
	 *
	 * @param array<string, mixed> $history Page metadata.
	 */
	private function render_pagination( array $history ): void {
		if ( 1 >= (int) $history['total_pages'] ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'history_page', '%#%', $this->history_url() ),
				'format'    => '',
				'current'   => (int) $history['page'],
				'total'     => (int) $history['total_pages'],
				'type'      => 'list',
				'prev_text' => __( '前へ', 'taxonomy-tidy' ),
				'next_text' => __( '次へ', 'taxonomy-tidy' ),
			)
		);
		if ( is_string( $links ) ) {
			?>
			<nav class="tablenav-pages" aria-label="<?php echo esc_attr__( '操作履歴のページ送り', 'taxonomy-tidy' ); ?>"><?php echo wp_kses_post( $links ); ?></nav>
			<?php
		}
	}

	/**
	 * Builds an owner-facing history URL.
	 *
	 * @param array<string, mixed> $args Additional query values.
	 */
	private function history_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => Page::SLUG,
					'view' => 'history',
				),
				$args
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Reads a positive read-only history query ID.
	 *
	 * @param string $key Query parameter name.
	 */
	private function query_id( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only value is unslashed, scalar checked, sanitized, and digit checked below.
		$value = wp_unslash( $_GET[ $key ] ?? '' );
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return ctype_digit( $value ) ? (int) $value : 0;
	}

	/**
	 * Returns a localized action summary.
	 *
	 * @param array<string, mixed> $operation Operation row.
	 */
	private function action_summary( array $operation ): string {
		if ( null !== $operation['parent_operation_id'] ) {
			return __( '取り消し', 'taxonomy-tidy' );
		}
		$actions = array_unique( array_column( (array) ( $operation['requested_data']['plan'] ?? array() ), 'action' ) );
		$labels  = array_map(
			static fn( string $action ): string => match ( $action ) {
				Action::RENAME->value => __( '名称変更', 'taxonomy-tidy' ),
				Action::MERGE->value => __( '統合', 'taxonomy-tidy' ),
				default => __( '削除', 'taxonomy-tidy' ),
			},
			$actions
		);
		return array() === $labels ? __( '実行済み操作', 'taxonomy-tidy' ) : implode( '、', $labels );
	}

	/**
	 * Counts requested source terms or Undo items.
	 *
	 * @param array<string, mixed> $operation Operation row.
	 */
	private function target_count( array $operation ): int {
		if ( null !== $operation['parent_operation_id'] ) {
			return (int) ( $operation['result_data']['total'] ?? count( (array) ( $operation['requested_data']['preview']['items'] ?? array() ) ) );
		}
		$count = 0;
		foreach ( (array) ( $operation['requested_data']['plan'] ?? array() ) as $item ) {
			$count += count( (array) ( $item['sources'] ?? array() ) );
		}
		return $count;
	}

	/**
	 * Returns the persisted target term names without looking up deleted terms.
	 *
	 * @param array<string, mixed> $operation Operation row.
	 */
	private function target_label( array $operation ): string {
		$names   = array();
		$preview = (array) ( $operation['requested_data']['preview']['items'] ?? array() );
		foreach ( $preview as $item ) {
			foreach ( (array) ( $item['sources'] ?? array() ) as $source ) {
				if ( '' !== (string) ( $source['name'] ?? '' ) ) {
					$names[] = (string) $source['name'];
				}
			}
		}
		if ( array() === $names ) {
			foreach ( $this->items->find_for_operation( (int) $operation['id'] ) as $item ) {
				$payload  = (array) $item['payload'];
				$snapshot = (array) ( $payload['source_snapshot'] ?? $payload['snapshot'] ?? $payload['before'] ?? array() );
				if ( '' !== (string) ( $snapshot['name'] ?? '' ) ) {
					$names[] = (string) $snapshot['name'];
				}
			}
		}
		return array() === $names ? __( '記録なし', 'taxonomy-tidy' ) : implode( '、', array_unique( $names ) );
	}

	/**
	 * Counts actual mutations for one operation.
	 *
	 * @param int $operation_id Operation ID.
	 */
	private function change_count( int $operation_id ): int {
		return count( array_filter( $this->journal->find_for_operation( $operation_id ), fn( array $change ): bool => $this->is_actual_change( (string) $change['change_type'] ) ) );
	}

	/**
	 * Distinguishes mutations from audit-only journal events.
	 *
	 * @param string $type Journal change type.
	 */
	private function is_actual_change( string $type ): bool {
		return ! in_array( $type, array( 'destination_existing', 'source_retained', 'item_failed', 'undo_item_failed' ), true );
	}

	/**
	 * Counts unique published posts appearing in relationship changes.
	 *
	 * @param list<array<string, mixed>> $changes Journal entries.
	 */
	private function affected_post_count( array $changes ): int {
		$ids = array();
		foreach ( $changes as $change ) {
			if ( in_array( $change['change_type'], array( 'destination_added', 'destination_existing', 'source_removed', 'source_restored', 'destination_removed' ), true ) && null !== $change['object_id'] ) {
				$ids[] = (int) $change['object_id'];
			}
		}
		return count( array_unique( $ids ) );
	}

	/**
	 * Counts safely retained merge sources.
	 *
	 * @param list<array<string, mixed>> $changes Journal entries.
	 */
	private function retained_count( array $changes ): int {
		return count( array_filter( $changes, static fn( array $change ): bool => 'source_retained' === $change['change_type'] ) );
	}

	/**
	 * Returns a localized persisted status.
	 *
	 * @param string $status Stored status.
	 */
	private function status_label( string $status ): string {
		return match ( $status ) {
			Status::RUNNING->value => __( '実行中', 'taxonomy-tidy' ),
			Status::COMPLETED->value => __( '完了', 'taxonomy-tidy' ),
			Status::PARTIAL_FAILED->value => __( '一部失敗', 'taxonomy-tidy' ),
			Status::FAILED->value => __( '失敗', 'taxonomy-tidy' ),
			Status::UNDO_PREVIEWED->value => __( '取り消し確認済み', 'taxonomy-tidy' ),
			Status::UNDOING->value => __( '取り消し中', 'taxonomy-tidy' ),
			Status::UNDONE->value => __( '取り消し済み', 'taxonomy-tidy' ),
			Status::UNDO_PARTIAL_FAILED->value => __( '一部のみ取り消し', 'taxonomy-tidy' ),
			default => __( '未実行', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns the three-level Undo availability label.
	 *
	 * @param string $availability Full, partial, or none.
	 */
	private function availability_label( string $availability ): string {
		return match ( $availability ) {
			'full' => __( '完全に取り消せる', 'taxonomy-tidy' ),
			'partial' => __( '一部のみ取り消せる', 'taxonomy-tidy' ),
			default => __( '取り消し不可', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns an Undo operation outcome for the history table.
	 *
	 * @param array<string, mixed> $operation Undo operation.
	 */
	private function undo_result_label( array $operation ): string {
		return match ( (string) $operation['status'] ) {
			Status::UNDONE->value => __( '取り消し済み', 'taxonomy-tidy' ),
			Status::UNDO_PARTIAL_FAILED->value => __( '一部のみ取り消し', 'taxonomy-tidy' ),
			Status::UNDOING->value => __( '取り消し中', 'taxonomy-tidy' ),
			default => __( '取り消し失敗', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns a non-technical Undo availability reason.
	 *
	 * @param string $reason Stable reason code.
	 */
	private function reason_label( string $reason ): string {
		return match ( $reason ) {
			'state_matches' => __( '現在の状態が実行直後と一致しています。', 'taxonomy-tidy' ),
			'some_conflicts' => __( '現在の状態と一致しない項目は変更せず、安全な項目だけを元に戻せます。', 'taxonomy-tidy' ),
			'journal_missing' => __( '復元に必要な変更履歴が保存されていません。', 'taxonomy-tidy' ),
			'undo_already_started' => __( 'この操作の取り消しはすでに開始されています。', 'taxonomy-tidy' ),
			'status_not_undoable' => __( '現在の操作状態では取り消しを開始できません。', 'taxonomy-tidy' ),
			default => __( '現在の状態では安全に元へ戻せません。', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns a localized supported taxonomy name.
	 *
	 * @param string $taxonomy Stored taxonomy.
	 */
	private function taxonomy_label( string $taxonomy ): string {
		return Taxonomy::CATEGORY->value === $taxonomy ? __( 'カテゴリー', 'taxonomy-tidy' ) : __( 'タグ', 'taxonomy-tidy' );
	}

	/**
	 * Formats a stored UTC timestamp in the WordPress timezone.
	 *
	 * @param string $date Stored UTC MySQL timestamp.
	 */
	private function date_label( string $date ): string {
		if ( '' === $date ) {
			return __( '—', 'taxonomy-tidy' );
		}
		$timestamp = strtotime( $date . ' UTC' );
		return false === $timestamp ? __( '—', 'taxonomy-tidy' ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Returns a safe localized warning or error summary.
	 *
	 * @param mixed $messages Stored message collection.
	 * @param bool  $warning  Whether this is a warning collection.
	 */
	private function message_summary( mixed $messages, bool $warning ): string {
		if ( ! is_array( $messages ) || array() === $messages ) {
			return __( 'なし', 'taxonomy-tidy' );
		}
		$parts = array();
		foreach ( $messages as $key => $value ) {
			$count = is_numeric( $value ) ? (int) $value : 1;
			if ( 'source_retained' === $key ) {
				$parts[] = sprintf( /* translators: %d: retained term count. */ __( '安全のため分類を保持：%d件', 'taxonomy-tidy' ), $count );
			} elseif ( 'conflicts' === $key ) {
				$parts[] = sprintf( /* translators: %d: conflict count. */ __( '競合：%d件', 'taxonomy-tidy' ), $count );
			} elseif ( 'item_failures' === $key ) {
				$parts[] = sprintf( /* translators: %d: failed item count. */ __( '処理失敗：%d件', 'taxonomy-tidy' ), $count );
			} else {
				$parts[] = $warning
					? sprintf( /* translators: %d: warning count. */ __( '警告：%d件', 'taxonomy-tidy' ), $count )
					: sprintf( /* translators: %d: error count. */ __( 'エラー：%d件', 'taxonomy-tidy' ), $count );
			}
		}
		return implode( '、', $parts );
	}

	/**
	 * Returns whether and how the original operation has been undone.
	 *
	 * @param array<string, mixed> $operation Operation row.
	 */
	private function undo_state_label( array $operation ): string {
		if ( null !== $operation['parent_operation_id'] ) {
			return $this->status_label( (string) $operation['status'] );
		}
		$undos = $this->operations->started_undos( (int) $operation['id'] );
		return array() === $undos ? __( '未実行', 'taxonomy-tidy' ) : $this->status_label( (string) $undos[0]['status'] );
	}

	/**
	 * Counts failed, conflicting, or pending inverse items.
	 *
	 * @param array<string, mixed> $operation Undo operation.
	 */
	private function remaining_count( array $operation ): int {
		$result = is_array( $operation['result_data'] ) ? $operation['result_data'] : array();
		return (int) ( $result['failed'] ?? 0 ) + (int) ( $result['pending'] ?? 0 ) + (int) ( $result['preview_conflicts'] ?? 0 );
	}

	/**
	 * Returns the non-technical object type involved in a conflict.
	 *
	 * @param string $type Journal change type.
	 */
	private function conflict_target_label( string $type ): string {
		return 'merge_relationship' === $type ? __( '対象投稿の割り当て', 'taxonomy-tidy' ) : __( '対象の分類', 'taxonomy-tidy' );
	}

	/**
	 * Returns the intended inverse action without exposing raw snapshots.
	 *
	 * @param string $type Journal change type.
	 */
	private function conflict_plan_label( string $type ): string {
		return match ( $type ) {
			'name_changed' => __( '予定していた復元：元の名前へ戻す', 'taxonomy-tidy' ),
			'slug_changed' => __( '予定していた復元：元のスラッグへ戻す', 'taxonomy-tidy' ),
			'merge_relationship' => __( '予定していた復元：元の投稿割り当てへ戻す', 'taxonomy-tidy' ),
			default => __( '予定していた復元：削除された分類を再作成する', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns a safe description of why current state was preserved.
	 *
	 * @param string $reason Stable conflict reason.
	 */
	private function conflict_current_label( string $reason ): string {
		return match ( $reason ) {
			'term_or_slug_exists', 'value_conflict' => __( '現在状態：同じ名前またはスラッグが使用されています。変更しません。', 'taxonomy-tidy' ),
			'parent_missing' => __( '現在状態：元の親カテゴリーが存在しません。変更しません。', 'taxonomy-tidy' ),
			'post_missing_or_changed' => __( '現在状態：対象投稿が存在しないか公開済み標準投稿ではありません。変更しません。', 'taxonomy-tidy' ),
			'assignment_changed' => __( '現在状態：投稿の割り当てが実行直後から変更されています。変更しません。', 'taxonomy-tidy' ),
			'source_changed', 'destination_changed', 'value_changed' => __( '現在状態：分類が実行直後から変更されています。変更しません。', 'taxonomy-tidy' ),
			default => __( '現在状態：安全な復元に必要な情報を確認できません。変更しません。', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns a human-readable actual change.
	 *
	 * @param array<string, mixed> $change Journal entry.
	 */
	private function change_label( array $change ): string {
		$type = (string) $change['change_type'];
		return match ( $type ) {
			'name_changed' => sprintf( /* translators: 1: old name, 2: new name. */ __( '名前：%1$s → %2$s', 'taxonomy-tidy' ), (string) ( $change['before_data']['name'] ?? '' ), (string) ( $change['after_data']['name'] ?? '' ) ),
			'slug_changed' => sprintf( /* translators: 1: old slug, 2: new slug. */ __( 'スラッグ：%1$s → %2$s', 'taxonomy-tidy' ), (string) ( $change['before_data']['slug'] ?? '' ), (string) ( $change['after_data']['slug'] ?? '' ) ),
			'destination_added' => __( '統合先の投稿割り当てを追加', 'taxonomy-tidy' ),
			'source_removed' => __( '統合元の投稿割り当てを削除', 'taxonomy-tidy' ),
			'source_deleted', 'term_deleted' => sprintf( /* translators: %s: deleted term name. */ __( '分類「%s」を削除', 'taxonomy-tidy' ), (string) ( $change['before_data']['name'] ?? '' ) ),
			'term_restored' => sprintf( /* translators: %s: restored term name. */ __( '分類「%s」を復元', 'taxonomy-tidy' ), (string) ( $change['after_data']['name'] ?? '' ) ),
			'source_restored' => __( '統合元の投稿割り当てを復元', 'taxonomy-tidy' ),
			'destination_removed' => __( '元操作が追加した統合先の割り当てを削除', 'taxonomy-tidy' ),
			'name_restored' => __( '名前を復元', 'taxonomy-tidy' ),
			default => __( 'スラッグを復元', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Maps internal error codes to safe localized text.
	 *
	 * @param string $code Stable error code.
	 */
	private function error_label( string $code ): string {
		return match ( $code ) {
			'invalid_nonce' => __( '操作の有効期限が切れました。画面を更新して再試行してください。', 'taxonomy-tidy' ),
			UndoErrorCode::NOT_AVAILABLE => __( 'この操作は安全に元へ戻せません。', 'taxonomy-tidy' ),
			UndoErrorCode::STALE_PREVIEW => __( '確認後に状態が変わったため、取り消しを開始しませんでした。もう一度確認してください。', 'taxonomy-tidy' ),
			UndoErrorCode::LOCKED => __( '別の処理が実行中です。しばらくしてから再試行してください。', 'taxonomy-tidy' ),
			default => __( '操作履歴または取り消し処理を確認できませんでした。', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Writes technical details only to the configured debug log.
	 *
	 * @param \Throwable $exception Internal failure.
	 */
	private function log_internal_error( \Throwable $exception ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Internal details are never rendered.
			error_log( sprintf( 'Taxonomy Tidy history error: %s: %s', $exception::class, $exception->getMessage() ) );
		}
	}
}
