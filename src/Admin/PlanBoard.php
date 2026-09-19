<?php
/**
 * Shared operation-plan tab for the two independent taxonomies.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

use TaxonomyTidy\Application\Execution\ExecutionException;
use TaxonomyTidy\Application\Execution\ExecutionWorkflow;
use TaxonomyTidy\Application\Planning\PlanErrorCode;
use TaxonomyTidy\Application\Planning\PlanService;
use TaxonomyTidy\Application\Planning\PlanValidationException;
use TaxonomyTidy\Application\Planning\PlanWorkflow;
use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;
use WP_Term;

/**
 * Displays both owned plans while preserving separate operation lifecycles.
 */
final class PlanBoard {
	/** Supported taxonomies in display and execution order. */
	private const TAXONOMIES = array( Taxonomy::CATEGORY, Taxonomy::POST_TAG );

	/** Last paired execution displayed to one administrator after reload. */
	private const LAST_RUN_META = 'taxonomy_tidy_last_board_run';

	/**
	 * Stores the existing planning and execution services.
	 *
	 * @param OperationRepository $operations Stored operations.
	 * @param PlanWorkflow        $workflow   Draft and preview workflow.
	 * @param PlanService         $plans      Read-only validation.
	 * @param ExecutionWorkflow   $execution Existing bounded execution.
	 */
	public function __construct(
		private readonly OperationRepository $operations,
		private readonly PlanWorkflow $workflow,
		private readonly PlanService $plans,
		private readonly ExecutionWorkflow $execution
	) {
	}

	/**
	 * Counts only the current administrator's draft items across both taxonomies.
	 *
	 * @param int $user_id Administrator ID.
	 * @throws \Throwable When saved operation data cannot be read.
	 */
	public function draft_count( int $user_id ): int {
		$count = 0;
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $this->operations->find_draft( $user_id, $taxonomy );
			if ( null !== $operation ) {
				$count += count( (array) ( $operation['requested_data']['plan'] ?? array() ) );
			}
		}
		return $count;
	}

	/**
	 * Handles authenticated board POSTs and returns the visible operation state.
	 *
	 * @return array<string, mixed>
	 * @throws PlanValidationException When a submitted board action is invalid.
	 */
	public function handle(): array {
		$user_id = get_current_user_id();
		$state   = array(
			'operations' => $this->load_operations( $user_id ),
			'errors'     => array(),
			'notice'     => null,
			'modal'      => false,
			'results'    => array(),
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Request method is used only for a fixed HTTP verb comparison.
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			if ( $this->has_running( $state['operations'] ) || array() === $state['operations'] ) {
				$state['results'] = $this->last_run_results( $user_id );
				if ( array() === $state['results'] && $this->has_running( $state['operations'] ) ) {
					$state['results'] = $state['operations'];
				}
			}
			if ( array() !== $state['results'] ) {
				$state['modal'] = true;
			}
			return $state;
		}
		if ( ! Access::current_user_can_access() ) {
			$state['errors'][] = PlanErrorCode::PERMISSION_DENIED;
			return $state;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- Type-checked, unslashed, sanitized, and verified below.
		$posted_nonce = $_POST[ PlanController::NONCE_FIELD ] ?? '';
		$nonce        = is_string( $posted_nonce ) ? sanitize_text_field( wp_unslash( $posted_nonce ) ) : '';
		if ( ! wp_verify_nonce( $nonce, PlanController::NONCE_ACTION ) ) {
			$state['errors'][] = PlanErrorCode::INVALID_NONCE;
			return $state;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above; command values are sanitized below.
		$request = wp_unslash( $_POST );
		$command = is_string( $request['plan_command'] ?? null ) ? sanitize_key( $request['plan_command'] ) : '';
		try {
			if ( 'preview_all' === $command ) {
				$this->preview_all( $user_id );
				$state['modal'] = true;
			} elseif ( 'run_all' === $command ) {
				$state['modal']   = true;
				$state['results'] = $this->run_all( $user_id );
			} elseif ( 'continue_all' === $command ) {
				$state['modal']   = true;
				$state['results'] = $this->continue_all( $user_id, $request );
			} elseif ( 'remove_item' === $command ) {
				$this->require_no_running( $user_id );
				$taxonomy = $this->requested_taxonomy( $request );
				$this->verify_draft_item( $user_id, $taxonomy, $request );
				$index = $this->item_index( $request );
				$this->workflow->remove( $user_id, $taxonomy, $index );
				$state['notice'] = __( '操作計画から削除しました。', 'taxonomy-tidy' );
			} elseif ( 'discard_all' === $command ) {
				$this->require_no_running( $user_id );
				if ( '1' !== (string) ( $request['confirmed'] ?? '' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
					throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
				}
				foreach ( self::TAXONOMIES as $taxonomy ) {
					$operation = $this->workflow->current( $user_id, $taxonomy );
					if ( null !== $operation ) {
						$this->workflow->discard( $user_id, $taxonomy );
					}
				}
				$state['notice'] = __( '操作計画を破棄しました。', 'taxonomy-tidy' );
			} else {
				$state['errors'][] = PlanErrorCode::PLAN_INVALID;
			}
		} catch ( PlanValidationException $exception ) {
			$state['errors'] = $exception->codes();
		} catch ( ExecutionException $exception ) {
			$state['errors'] = array( $exception->error_code() );
		} catch ( \Throwable $exception ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Internal diagnostic only.
			}
			$state['errors'] = array( PlanErrorCode::UNKNOWN_ERROR );
		}
		$state['operations'] = $this->load_operations( $user_id );
		return $state;
	}

	/**
	 * Validates every taxonomy before changing either preview state.
	 *
	 * @param int $user_id Administrator ID.
	 * @throws PlanValidationException When there is no valid plan.
	 */
	private function preview_all( int $user_id ): void {
		$operations = $this->load_operations( $user_id );
		$has_plan   = false;
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $operations[ $taxonomy->value ] ?? null;
			if ( null === $operation ) {
				continue;
			}
			if ( Status::RUNNING->value === $operation['status'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
				throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
			}
			$plan = (array) ( $operation['requested_data']['plan'] ?? array() );
			if ( array() === $plan ) {
				continue;
			}
			$has_plan = true;
			if ( Status::DRAFT->value === $operation['status'] ) {
				$this->plans->preview( $taxonomy, $plan );
			} else {
				$this->execution->validate_start( (int) $operation['id'], $user_id, $taxonomy );
			}
		}
		if ( ! $has_plan ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $operations[ $taxonomy->value ] ?? null;
			if ( null !== $operation && Status::DRAFT->value === $operation['status'] && array() !== (array) ( $operation['requested_data']['plan'] ?? array() ) ) {
				$this->workflow->preview( $user_id, $taxonomy );
			}
		}
	}

	/**
	 * Preflights both previews before the first batch starts.
	 *
	 * @param int $user_id Administrator ID.
	 * @return array<string, array<string, mixed>>
	 * @throws PlanValidationException When no preview exists.
	 */
	private function run_all( int $user_id ): array {
		$operations = $this->load_operations( $user_id );
		$start      = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $operations[ $taxonomy->value ] ?? null;
			if ( null === $operation ) {
				continue;
			}
			$this->execution->validate_start( (int) $operation['id'], $user_id, $taxonomy );
			$start[ $taxonomy->value ] = (int) $operation['id'];
		}
		if ( array() === $start ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		update_user_meta( $user_id, self::LAST_RUN_META, $start );
		return $this->run_ids( $user_id, $start );
	}

	/**
	 * Continues only owned, running operations named by the previous result.
	 *
	 * @param int                  $user_id Administrator ID.
	 * @param array<string, mixed> $request Verified request.
	 * @return array<string, array<string, mixed>>
	 * @throws PlanValidationException When the resume request is invalid.
	 */
	private function continue_all( int $user_id, array $request ): array {
		$ids = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$id = absint( ( (array) ( $request['operation_ids'] ?? array() ) )[ $taxonomy->value ] ?? 0 );
			if ( 0 === $id ) {
				continue;
			}
			$operation = $this->operations->find( $id );
			if ( null === $operation || $user_id !== (int) $operation['user_id'] || $taxonomy->value !== $operation['taxonomy'] || ! in_array( $operation['status'], array( Status::RUNNING->value, Status::COMPLETED->value, Status::PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
				throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
			}
			$ids[ $taxonomy->value ] = $id;
		}
		if ( array() === $ids ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		return $this->run_ids( $user_id, $ids );
	}

	/**
	 * Applies each operation through the existing bounded workflow.
	 *
	 * @param int                $user_id Administrator ID.
	 * @param array<string, int> $ids     Taxonomy-keyed operation IDs.
	 * @return array<string, array<string, mixed>>
	 * @throws PlanValidationException When an operation is not owned by the administrator.
	 */
	private function run_ids( int $user_id, array $ids ): array {
		$results = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$id = $ids[ $taxonomy->value ] ?? 0;
			if ( 0 === $id ) {
				continue;
			}
			$operation = $this->operations->find( $id );
			if ( null === $operation || $user_id !== (int) $operation['user_id'] || $taxonomy->value !== $operation['taxonomy'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
				throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
			}
			if ( in_array( $operation['status'], array( Status::COMPLETED->value, Status::PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) {
				$results[ $taxonomy->value ] = $operation;
				continue;
			}
			try {
				$results[ $taxonomy->value ] = $this->execution->run_batch( $id, $user_id, $taxonomy );
			} catch ( ExecutionException $exception ) {
				$operation                   = $this->operations->find( $id ) ?? $operation;
				$operation['board_error']    = $exception->error_code();
				$results[ $taxonomy->value ] = $operation;
			} catch ( \Throwable $exception ) {
				$operation                   = $this->operations->find( $id ) ?? $operation;
				$operation['board_error']    = PlanErrorCode::UNKNOWN_ERROR;
				$results[ $taxonomy->value ] = $operation;
			}
		}
		return $results;
	}

	/**
	 * Loads only operations owned by the current administrator.
	 *
	 * @param int $user_id Administrator ID.
	 * @return array<string, array<string, mixed>>
	 */
	private function load_operations( int $user_id ): array {
		$operations = array();
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $this->execution->latest( $user_id, $taxonomy );
			if ( null === $operation ) {
				$operation = $this->workflow->current( $user_id, $taxonomy );
			}
			if ( null !== $operation && ( Status::DRAFT->value !== $operation['status'] || array() !== (array) ( $operation['requested_data']['plan'] ?? array() ) ) ) {
				$operations[ $taxonomy->value ] = $operation;
			}
		}
		return $operations;
	}

	/**
	 * Reloads the two independent statuses from the last combined start.
	 *
	 * @param int $user_id Administrator ID.
	 * @return array<string, array<string, mixed>>
	 */
	private function last_run_results( int $user_id ): array {
		$ids     = get_user_meta( $user_id, self::LAST_RUN_META, true );
		$results = array();
		if ( ! is_array( $ids ) ) {
			return $results;
		}
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$id = absint( $ids[ $taxonomy->value ] ?? 0 );
			if ( 0 === $id ) {
				continue;
			}
			$operation = $this->operations->find( $id );
			if ( null === $operation || $user_id !== (int) $operation['user_id'] || $taxonomy->value !== $operation['taxonomy'] || ! in_array( $operation['status'], array( Status::RUNNING->value, Status::COMPLETED->value, Status::PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) {
				continue;
			}
			$operation['progress']       = $operation['result_data'];
			$results[ $taxonomy->value ] = $operation;
		}
		return $results;
	}

	/**
	 * Refuses plan changes while a previous batch is unfinished.
	 *
	 * @param int $user_id Administrator ID.
	 * @throws PlanValidationException When a batch is running.
	 */
	private function require_no_running( int $user_id ): void {
		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( null !== $this->execution->latest( $user_id, $taxonomy ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
				throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
			}
		}
	}

	/**
	 * Accepts only a supported taxonomy for an item operation.
	 *
	 * @param array<string, mixed> $request Verified request.
	 * @throws PlanValidationException When invalid.
	 */
	private function requested_taxonomy( array $request ): Taxonomy {
		$value    = is_string( $request['taxonomy'] ?? null ) ? sanitize_key( $request['taxonomy'] ) : '';
		$taxonomy = Taxonomy::tryFrom( $value );
		if ( null === $taxonomy ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::TAXONOMY_MISMATCH ) );
		}
		return $taxonomy;
	}

	/**
	 * Rejects deletion outside the current owned draft or against a changed plan.
	 *
	 * @param int                  $user_id  Administrator ID.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 * @param array<string, mixed> $request  Verified request.
	 * @throws PlanValidationException When the draft or item identity changed.
	 */
	private function verify_draft_item( int $user_id, Taxonomy $taxonomy, array $request ): void {
		$operation = $this->workflow->current( $user_id, $taxonomy );
		$posted_id = is_string( $request['operation_id'] ?? null ) ? $request['operation_id'] : '';
		$posted    = is_string( $request['expected_plan_hash'] ?? null ) ? sanitize_text_field( $request['expected_plan_hash'] ) : '';
		if ( null === $operation || Status::DRAFT->value !== $operation['status'] || ! ctype_digit( $posted_id ) || (int) $operation['id'] !== (int) $posted_id || '' === $posted || ! hash_equals( $this->plans->plan_hash( (array) ( $operation['requested_data']['plan'] ?? array() ) ), $posted ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
	}

	/**
	 * Reads a nonnegative item index from a verified request.
	 *
	 * @param array<string, mixed> $request Verified request.
	 * @throws PlanValidationException When the index is malformed.
	 */
	private function item_index( array $request ): int {
		$value = $request['item_index'] ?? null;
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation code only.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		return (int) $value;
	}

	/**
	 * Renders the shared plan and one combined preview/result dialog.
	 *
	 * @param array<string, mixed> $state Controller state.
	 */
	public function render( array $state ): void {
		$operations = (array) $state['operations'];
		$count      = 0;
		foreach ( $operations as $operation ) {
			$count += count( (array) ( $operation['requested_data']['plan'] ?? array() ) );
		}
		?>
		<div id="taxonomy-tidy-board-content">
		<h2><?php echo esc_html__( '操作計画', 'taxonomy-tidy' ); ?></h2>
		<?php if ( array() !== $state['errors'] ) : ?>
			<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( implode( ' ', array_map( array( $this, 'error_label' ), $state['errors'] ) ) ); ?></p></div>
		<?php elseif ( is_string( $state['notice'] ) ) : ?>
			<div class="notice notice-success inline" role="status"><p><?php echo esc_html( $state['notice'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( 0 === $count ) : ?>
			<p><?php echo esc_html__( '操作計画はまだありません。', 'taxonomy-tidy' ); ?><br><?php echo esc_html__( 'カテゴリーまたはタグを選択し、処理パネルから計画へ追加してください。', 'taxonomy-tidy' ); ?></p>
			<?php if ( $state['modal'] ) : ?>
				<form id="taxonomy-tidy-board-form" method="post">
					<input type="hidden" name="<?php echo esc_attr( PlanController::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( PlanController::NONCE_ACTION ) ); ?>">
					<input type="hidden" name="view" value="plan">
				</form>
			<?php endif; ?>
		<?php else : ?>
			<?php foreach ( self::TAXONOMIES as $taxonomy ) : ?>
				<?php $operation = $operations[ $taxonomy->value ] ?? null; ?>
				<?php
				if ( null === $operation || array() === (array) ( $operation['requested_data']['plan'] ?? array() ) ) {
					continue;
				}
				$assessment = null;
				if ( Status::DRAFT->value === $operation['status'] ) {
					try {
						$assessment = $this->plans->preview( $taxonomy, (array) $operation['requested_data']['plan'] );
					} catch ( \Throwable $exception ) {
						$assessment = null;
					}
				} elseif ( Status::PREVIEWED->value === $operation['status'] ) {
					$assessment = $operation['requested_data']['preview'] ?? null;
				}
				?>
				<h3><?php echo esc_html( $this->taxonomy_label( $taxonomy ) ); ?></h3>
			<table class="wp-list-table widefat fixed striped taxonomy-tidy-plan-table"><thead><tr><th scope="col"><?php echo esc_html__( '処理方法', 'taxonomy-tidy' ); ?></th><th scope="col"><?php echo esc_html__( '種別', 'taxonomy-tidy' ); ?></th><th scope="col"><?php echo esc_html__( '対象', 'taxonomy-tidy' ); ?></th><th scope="col"><?php echo esc_html__( '変更内容', 'taxonomy-tidy' ); ?></th><th scope="col"><?php echo esc_html__( '状態', 'taxonomy-tidy' ); ?></th><th scope="col" class="taxonomy-tidy-plan-table__delete"><span class="screen-reader-text"><?php echo esc_html__( '削除', 'taxonomy-tidy' ); ?></span></th></tr></thead><tbody>
				<?php foreach ( (array) $operation['requested_data']['plan'] as $index => $item ) : ?>
					<?php
					$warnings     = is_array( $assessment ) ? (array) ( $assessment['items'][ $index ]['warnings'] ?? array() ) : array();
					$status_label = Status::RUNNING->value === $operation['status']
						? __( '処理中', 'taxonomy-tidy' )
						: ( null === $assessment || false === ( $operation['preview_current'] ?? true )
							? __( 'エラーあり', 'taxonomy-tidy' )
							: ( array() === $warnings ? __( '実行可能', 'taxonomy-tidy' ) : __( '警告あり', 'taxonomy-tidy' ) ) );
					?>
					<tr><td><?php echo esc_html( $this->action_label( (string) $item['action'] ) ); ?></td><td><?php echo esc_html( $this->taxonomy_label( $taxonomy ) ); ?></td><td><?php echo esc_html( $this->source_names( $item ) ); ?></td><td><?php echo esc_html( $this->change_label( $item ) ); ?></td><td><?php echo esc_html( $status_label ); ?></td><td class="taxonomy-tidy-plan-table__delete">
					<?php if ( Status::DRAFT->value === $operation['status'] ) : ?>
						<form method="post"><input type="hidden" name="<?php echo esc_attr( PlanController::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( PlanController::NONCE_ACTION ) ); ?>"><input type="hidden" name="view" value="plan"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>"><input type="hidden" name="operation_id" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"><input type="hidden" name="item_index" value="<?php echo esc_attr( (string) $index ); ?>"><input type="hidden" name="expected_plan_hash" value="<?php echo esc_attr( $this->plans->plan_hash( (array) $operation['requested_data']['plan'] ) ); ?>"><button class="button-link-delete taxonomy-tidy-plan-delete" type="submit" name="plan_command" value="remove_item" aria-label="<?php /* translators: 1: taxonomy, 2: target term names, 3: action name. */ echo esc_attr( sprintf( __( '%1$s「%2$s」の%3$sを操作計画から削除', 'taxonomy-tidy' ), $this->taxonomy_label( $taxonomy ), $this->source_names( $item ), $this->action_label( (string) $item['action'] ) ) ); ?>"><?php echo esc_html__( '削除', 'taxonomy-tidy' ); ?></button></form>
					<?php endif; ?>
				</td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endforeach; ?>
			<form id="taxonomy-tidy-board-form" method="post">
				<input type="hidden" name="<?php echo esc_attr( PlanController::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( PlanController::NONCE_ACTION ) ); ?>">
				<input type="hidden" name="view" value="plan">
				<div class="taxonomy-tidy-board-actions"><button type="submit" class="button-link-delete taxonomy-tidy-discard" name="plan_command" value="discard_all" data-confirm="<?php echo esc_attr__( '編集中の操作計画をすべて破棄しますか？', 'taxonomy-tidy' ); ?>" <?php disabled( $this->has_running( $operations ) ); ?>><?php echo esc_html__( '計画をすべて破棄', 'taxonomy-tidy' ); ?></button><input type="hidden" name="confirmed" value="0"><button type="submit" class="button button-primary" name="plan_command" value="preview_all" <?php disabled( $this->has_running( $operations ) ); ?>><?php echo esc_html__( '変更内容を確認', 'taxonomy-tidy' ); ?></button></div>
			</form>
		<?php endif; ?>
		</div>
		<?php if ( $state['modal'] || $this->has_preview( $operations ) ) : ?>
			<?php $this->render_modal( $state ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the preview or progress dialog.
	 *
	 * @param array<string, mixed> $state Board state.
	 */
	private function render_modal( array $state ): void {
		$results = (array) $state['results'];
		$active  = array() !== $results;
		?>
		<div class="taxonomy-tidy-modal taxonomy-tidy-board-modal" data-auto-open="<?php echo $state['modal'] ? '1' : '0'; ?>" hidden>
			<div class="taxonomy-tidy-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="taxonomy-tidy-preview-heading" tabindex="-1">
				<header class="taxonomy-tidy-modal__header"><h2 id="taxonomy-tidy-preview-heading"><?php echo esc_html( $active ? __( '実行結果', 'taxonomy-tidy' ) : __( '変更内容のプレビュー', 'taxonomy-tidy' ) ); ?></h2><button type="button" class="taxonomy-tidy-modal__close" aria-label="<?php echo esc_attr__( '閉じる', 'taxonomy-tidy' ); ?>" <?php disabled( $this->has_running( $results ) ); ?>>&times;</button></header>
				<div class="taxonomy-tidy-modal__body" aria-live="polite">
					<?php if ( $active ) : ?>
						<?php $this->render_results( $results ); ?>
					<?php else : ?>
						<?php $this->render_previews( (array) $state['operations'] ); ?>
					<?php endif; ?>
				</div>
				<footer class="taxonomy-tidy-modal__footer"><button type="button" class="button taxonomy-tidy-modal__cancel" <?php disabled( $this->has_running( $results ) ); ?>><?php echo esc_html__( 'キャンセル', 'taxonomy-tidy' ); ?></button>
				<?php if ( $active ) : ?>
					<?php
					if ( $this->has_running( $results ) ) :
						?>
						<button type="submit" form="taxonomy-tidy-board-form" class="button button-primary" name="plan_command" value="continue_all"><?php echo esc_html__( '次の処理を続ける', 'taxonomy-tidy' ); ?></button><?php endif; ?>
					<?php
					foreach ( $results as $taxonomy => $operation ) :
						?>
						<input type="hidden" form="taxonomy-tidy-board-form" name="operation_ids[<?php echo esc_attr( $taxonomy ); ?>]" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"><?php endforeach; ?>
				<?php else : ?>
					<button type="submit" form="taxonomy-tidy-board-form" class="button button-primary" name="plan_command" value="run_all" <?php disabled( array() !== $state['errors'] || $this->has_draft( (array) $state['operations'] ) || $this->has_running( (array) $state['operations'] ) || $this->has_stale_preview( (array) $state['operations'] ) ); ?>><?php echo esc_html__( '実行', 'taxonomy-tidy' ); ?></button>
				<?php endif; ?>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the two previews in taxonomy groups.
	 *
	 * @param array<string, array<string, mixed>> $operations Previewed operations.
	 */
	private function render_previews( array $operations ): void {
		$count = 0;
		foreach ( $operations as $operation ) {
			if ( Status::PREVIEWED->value === $operation['status'] ) {
				$count += count( (array) ( $operation['requested_data']['preview']['items'] ?? array() ) );
			}
		}
		?>
		<p><?php /* translators: %d: number of planned actions. */ echo esc_html( sprintf( __( '処理件数：%d件', 'taxonomy-tidy' ), $count ) ); ?></p>
		<?php
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$operation = $operations[ $taxonomy->value ] ?? null;
			if ( null === $operation || Status::PREVIEWED->value !== $operation['status'] ) {
				continue;
			}
			$preview = $operation['requested_data']['preview'] ?? null;
			if ( ! is_array( $preview ) ) {
				continue;
			}
			?>
			<h3><?php echo esc_html( $this->taxonomy_label( $taxonomy ) ); ?></h3>
			<?php foreach ( (array) ( $preview['items'] ?? array() ) as $index => $item ) : ?>
				<article class="taxonomy-tidy-preview-item"><h4><?php echo esc_html( $this->action_label( (string) $item['action'] ) ); ?></h4><p><?php echo esc_html( implode( '、', array_column( (array) $item['sources'], 'name' ) ) ); ?> → <?php echo esc_html( $this->change_label( $item ) ); ?></p><p><?php /* translators: %d: affected published post count. */ echo esc_html( sprintf( __( '影響を受ける公開済み投稿：%d件', 'taxonomy-tidy' ), count( (array) $item['affected_posts'] ) ) ); ?></p>
				<?php
				foreach ( (array) $item['sources'] as $source ) :
					?>
					<?php
					if ( Action::RENAME->value !== $item['action'] ) :
						?>
					<p><?php echo esc_html( (string) $source['name'] ); ?>：<?php echo esc_html( $source['delete_source'] ? __( '処理後に削除', 'taxonomy-tidy' ) : __( '削除せず保持', 'taxonomy-tidy' ) ); ?></p><?php endif; ?><?php endforeach; ?>
				<?php
				if ( array() !== (array) $item['warnings'] ) :
					?>
					<p class="taxonomy-tidy-message taxonomy-tidy-message--warning"><?php echo esc_html( implode( ' ', array_map( array( $this, 'warning_label' ), $item['warnings'] ) ) ); ?></p><?php endif; ?>
				<?php
				if ( array() !== (array) $item['affected_posts'] ) :
					?>
					<details class="taxonomy-tidy-preview-posts" data-taxonomy="<?php echo esc_attr( $taxonomy->value ); ?>" data-operation="<?php echo esc_attr( (string) $operation['id'] ); ?>" data-item="<?php echo esc_attr( (string) $index ); ?>" data-error="<?php echo esc_attr__( '対象投稿を取得できませんでした。', 'taxonomy-tidy' ); ?>"><summary><?php echo esc_html__( '対象投稿を確認', 'taxonomy-tidy' ); ?></summary><ul></ul></details><?php endif; ?>
				</article>
			<?php endforeach; ?>
			<?php
		}
	}

	/**
	 * Renders separate results and the combined outcome.
	 *
	 * @param array<string, array<string, mixed>> $results Individual operation results.
	 */
	private function render_results( array $results ): void {
		$failed    = false;
		$succeeded = false;
		foreach ( $results as $result ) {
			$failed    = $failed || isset( $result['board_error'] ) || in_array( $result['status'], array( Status::FAILED->value, Status::PARTIAL_FAILED->value ), true );
			$succeeded = $succeeded || in_array( $result['status'], array( Status::COMPLETED->value, Status::PARTIAL_FAILED->value ), true );
		}
		if ( $failed ) {
			?>
			<p class="taxonomy-tidy-message taxonomy-tidy-message--warning"><?php echo esc_html( $succeeded ? __( '一部の処理に失敗しました。成功した処理の結果は保持されています。', 'taxonomy-tidy' ) : __( '処理を完了できませんでした。', 'taxonomy-tidy' ) ); ?></p>
			<?php
		}
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$result = $results[ $taxonomy->value ] ?? null;
			if ( null === $result ) {
				continue;
			}
			$status = (string) $result['status'];
			$label  = match ( $status ) {
				Status::COMPLETED->value => __( '完了', 'taxonomy-tidy' ),
				Status::RUNNING->value => __( '処理中', 'taxonomy-tidy' ),
				Status::PARTIAL_FAILED->value => __( '一部失敗', 'taxonomy-tidy' ),
				default => __( '失敗', 'taxonomy-tidy' ),
			};
			?>
			<p><strong><?php echo esc_html( $this->taxonomy_label( $taxonomy ) ); ?>：</strong><?php echo esc_html( $label ); ?>
			<?php
			if ( isset( $result['board_error'] ) ) :
				?>
				— <?php echo esc_html( ErrorMessages::label( (string) $result['board_error'] ) ); ?><?php endif; ?></p>
			<?php if ( is_array( $result['progress'] ?? null ) ) : ?>
				<p><?php /* translators: 1: completed count, 2: total count, 3: failed count. */ echo esc_html( sprintf( __( '完了 %1$d / 全体 %2$d、失敗 %3$d', 'taxonomy-tidy' ), (int) $result['progress']['completed'], (int) $result['progress']['total'], (int) $result['progress']['failed'] ) ); ?></p>
			<?php endif; ?>
			<?php
		}
	}

	/**
	 * Checks a status in the currently visible operations.
	 *
	 * @param array<string, array<string, mixed>> $operations Operation map.
	 */
	private function has_draft( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( Status::DRAFT->value === $operation['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks a status in the currently visible operations.
	 *
	 * @param array<string, array<string, mixed>> $operations Operation map.
	 */
	private function has_preview( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( Status::PREVIEWED->value === $operation['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks a status in the currently visible operations.
	 *
	 * @param array<string, array<string, mixed>> $operations Operation map.
	 */
	private function has_running( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( Status::RUNNING->value === $operation['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether an existing preview no longer matches current term state.
	 *
	 * @param array<string, array<string, mixed>> $operations Operation map.
	 */
	private function has_stale_preview( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( Status::PREVIEWED->value === $operation['status'] && false === ( $operation['preview_current'] ?? true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns or renders taxonomy-specific labels and destinations.
	 *
	 * @param Taxonomy $taxonomy Supported taxonomy.
	 */
	private function taxonomy_label( Taxonomy $taxonomy ): string {
		return Taxonomy::CATEGORY === $taxonomy ? __( 'カテゴリー', 'taxonomy-tidy' ) : __( 'タグ', 'taxonomy-tidy' );
	}

	/**
	 * Returns the localized action name.
	 *
	 * @param string $action Stable action name.
	 */
	private function action_label( string $action ): string {
		return match ( $action ) {
			Action::RENAME->value => __( '名称変更', 'taxonomy-tidy' ),
			Action::MERGE->value => __( '統合', 'taxonomy-tidy' ),
			default => __( '削除', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns a readable summary for one item.
	 *
	 * @param array<string, mixed> $item Stored plan or preview item.
	 */
	private function source_names( array $item ): string {
		$names = array();
		foreach ( (array) ( $item['sources'] ?? array() ) as $source ) {
			if ( isset( $source['name'] ) ) {
				$names[] = (string) $source['name'];
			} else {
				$term    = get_term( (int) ( $source['term_id'] ?? 0 ) );
				$names[] = $term instanceof WP_Term ? $term->name : __( '見つからない分類', 'taxonomy-tidy' );
			}
		}
		return implode( '、', $names );
	}

	/**
	 * Returns a readable summary for one item.
	 *
	 * @param array<string, mixed> $item Stored plan or preview item.
	 */
	private function change_label( array $item ): string {
		if ( Action::RENAME->value === $item['action'] ) {
			$label = (string) ( $item['new_name'] ?? '' );
			return null === ( $item['new_slug'] ?? null ) ? $label : $label . ' / ' . (string) $item['new_slug'];
		}
		if ( Action::MERGE->value === $item['action'] ) {
			$destination = $item['destination'] ?? null;
			if ( is_array( $destination ) && isset( $destination['name'] ) ) {
				return (string) $destination['name'];
			}
			$term = is_array( $destination ) ? get_term( (int) ( $destination['term_id'] ?? 0 ) ) : null;
			return $term instanceof WP_Term ? $term->name : __( '見つからない分類', 'taxonomy-tidy' );
		}
		return __( '分類を削除', 'taxonomy-tidy' );
	}

	/**
	 * Returns a localized preview warning.
	 *
	 * @param string $warning Stable preview warning.
	 */
	private function warning_label( string $warning ): string {
		return match ( $warning ) {
			'used_by_excluded_objects' => __( '対象外の投稿で使用中のため分類を保持します。', 'taxonomy-tidy' ),
			'has_child_categories' => __( '子カテゴリーがあるため分類を保持します。', 'taxonomy-tidy' ),
			default => __( '処理前に確認が必要です。', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns Japanese messages for invalid board requests.
	 *
	 * @param string $code Stable internal error code.
	 */
	private function error_label( string $code ): string {
		return match ( $code ) {
			PlanErrorCode::PLAN_INVALID => __( '操作計画が変更されたか、この操作を行えない状態です。画面を更新して確認してください。', 'taxonomy-tidy' ),
			PlanErrorCode::PERMISSION_DENIED => __( 'この操作を行う権限がありません。', 'taxonomy-tidy' ),
			PlanErrorCode::INVALID_NONCE => __( '操作の有効期限が切れました。画面を更新して再試行してください。', 'taxonomy-tidy' ),
			PlanErrorCode::TAXONOMY_MISMATCH => __( '対象のカテゴリーまたはタグが正しくありません。', 'taxonomy-tidy' ),
			PlanErrorCode::UNKNOWN_ERROR => __( '操作計画を処理できませんでした。もう一度お試しください。', 'taxonomy-tidy' ),
			default => ErrorMessages::label( $code ),
		};
	}
}
