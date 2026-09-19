<?php
/**
 * Bounded, retry-safe Undo workflow.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Undo;

use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\DatabaseTransaction;
use TaxonomyTidy\Infrastructure\Persistence\OperationItemRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationLock;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;

/** Coordinates preview validation, fixed inverse items, leases, and outcomes. */
final class UndoWorkflow {
	public const BATCH_SIZE = 10;
	private const LOCK_TTL  = 60;

	/**
	 * Creates the bounded Undo coordinator.
	 *
	 * @param OperationRepository     $operations Stored operations.
	 * @param OperationItemRepository $items      Fixed inverse items.
	 * @param OperationLock           $lock       Taxonomy-scoped lease.
	 * @param UndoPlanner             $planner    Current-state assessor.
	 * @param UndoItemExecutor        $executor   Single inverse executor.
	 * @param DatabaseTransaction     $transaction Per-item transaction.
	 */
	public function __construct(
		private readonly OperationRepository $operations,
		private readonly OperationItemRepository $items,
		private readonly OperationLock $lock,
		private readonly UndoPlanner $planner,
		private readonly UndoItemExecutor $executor,
		private readonly DatabaseTransaction $transaction
	) {
	}

	/**
	 * Starts or resumes one bounded Undo request.
	 *
	 * @param int $undo_id Undo operation ID.
	 * @param int $user_id Current administrator ID.
	 * @return array<string, mixed>
	 * @throws UndoException When the request is stale, conflicting, or invalid.
	 * @throws \Throwable When persistence cannot record a terminal item state.
	 */
	public function run_batch( int $undo_id, int $user_id ): array {
		$undo = $this->operations->find_owned( $undo_id, $user_id );
		if ( null === $undo || null === $undo['parent_operation_id'] || 'undo' !== ( $undo['requested_data']['kind'] ?? '' ) ) {
			$this->failure( UndoErrorCode::INVALID_OPERATION );
		}
		$status = Status::tryFrom( (string) $undo['status'] );
		if ( ! in_array( $status, array( Status::UNDO_PREVIEWED, Status::UNDOING ), true ) ) {
			$this->failure( UndoErrorCode::INVALID_OPERATION );
		}
		$taxonomy = Taxonomy::from( (string) $undo['taxonomy'] );
		$token    = $this->lock->acquire( $undo_id, self::LOCK_TTL );
		if ( null === $token ) {
			$this->failure( UndoErrorCode::LOCKED );
		}
		try {
			if ( Status::UNDO_PREVIEWED === $status ) {
				$this->start( $undo, $user_id );
			}
			foreach ( $this->items->find_pending( $undo_id, self::BATCH_SIZE ) as $item ) {
				if ( ! $this->lock->renew( $undo_id, $token, self::LOCK_TTL ) || ! $this->items->record_attempt( (int) $item['id'] ) ) {
					$this->failure( UndoErrorCode::LOCKED );
				}
				try {
					if ( ! $this->transaction->begin() ) {
						$this->failure( UndoErrorCode::UPDATE_FAILED );
					}
					$this->executor->execute( $item, $taxonomy );
					if ( ! $this->items->mark_completed( (int) $item['id'] ) || ! $this->transaction->commit() ) {
						$this->failure( UndoErrorCode::JOURNAL_FAILED );
					}
				} catch ( UndoException $exception ) {
					$this->transaction->rollback();
					$this->executor->record_failure( $item, $exception->error_code(), $taxonomy );
					if ( ! $this->items->mark_failed( (int) $item['id'], $exception->error_code() ) ) {
						throw $exception;
					}
				} catch ( \Throwable $exception ) {
					$this->transaction->rollback();
					if ( ! $this->items->mark_failed( (int) $item['id'], UndoErrorCode::JOURNAL_FAILED ) ) {
						throw $exception;
					}
				}
			}
			$this->finish_if_ready( $undo_id );
			return $this->result( $undo_id );
		} finally {
			$this->lock->release( $undo_id, $token );
		}
	}

	/**
	 * Revalidates the preview and seeds fixed inverse items.
	 *
	 * @param array<string, mixed> $undo    Undo preview operation.
	 * @param int                  $user_id Current administrator ID.
	 */
	private function start( array $undo, int $user_id ): void {
		$original_id = (int) $undo['parent_operation_id'];
		$assessment  = $this->planner->assess( $original_id, $user_id, (int) $undo['id'] );
		$stored      = is_array( $undo['requested_data']['preview'] ?? null ) ? $undo['requested_data']['preview'] : array();
		if ( 'none' === $assessment['availability'] || ! is_string( $undo['plan_hash'] ) || ! hash_equals( $undo['plan_hash'], $this->planner->plan_hash( (array) $assessment['items'] ) ) || ! is_string( $undo['state_fingerprint'] ) || ! hash_equals( $undo['state_fingerprint'], (string) $assessment['fingerprint'] ) || (string) ( $stored['fingerprint'] ?? '' ) !== (string) $assessment['fingerprint'] ) {
			$this->failure( UndoErrorCode::STALE_PREVIEW );
		}
		foreach ( (array) $stored['items'] as $index => $payload ) {
			$payload['original_operation_id'] = $original_id;
			$this->items->add_once( (int) $undo['id'], sprintf( '%04d:%s', $index, (string) $payload['kind'] ), Action::UNDO, $payload );
		}
		$this->operations->transition( (int) $undo['id'], Status::UNDOING );
	}

	/**
	 * Finalizes a fully processed Undo operation truthfully.
	 *
	 * @param int $undo_id Undo operation ID.
	 */
	private function finish_if_ready( int $undo_id ): void {
		$progress = $this->items->progress( $undo_id );
		if ( 0 !== $progress['pending'] ) {
			$this->operations->save_result( $undo_id, $progress );
			return;
		}
		$undo              = $this->operations->find( $undo_id );
		$preview_conflicts = count( (array) ( $undo['requested_data']['preview']['conflicts'] ?? array() ) );
		if ( 0 === $progress['completed'] ) {
			$status = Status::FAILED;
		} elseif ( 0 < $progress['failed'] || 0 < $preview_conflicts ) {
			$status = Status::UNDO_PARTIAL_FAILED;
		} else {
			$status = Status::UNDONE;
		}
		$result = array_merge( $progress, array( 'preview_conflicts' => $preview_conflicts ) );
		$this->operations->save_result( $undo_id, $result, 0 < $progress['failed'] ? array( 'item_failures' => $progress['failed'] ) : array(), 0 < $preview_conflicts ? array( 'conflicts' => $preview_conflicts ) : array() );
		$this->operations->transition( $undo_id, $status );
	}

	/**
	 * Returns fresh persisted progress.
	 *
	 * @param int $undo_id Undo operation ID.
	 * @return array<string, mixed>
	 */
	private function result( int $undo_id ): array {
		$undo             = $this->operations->find( $undo_id ) ?? array();
		$undo['progress'] = $this->items->progress( $undo_id );
		return $undo;
	}

	/**
	 * Throws a coded Undo failure.
	 *
	 * @param string $code Stable error code.
	 * @return never
	 * @throws UndoException Always.
	 */
	private function failure( string $code ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable internal code only.
		throw new UndoException( $code );
	}
}
