<?php
/**
 * Batched execution and recovery workflow.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Execution;

use TaxonomyTidy\Application\Planning\PlanService;
use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\OperationItemRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationLock;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;
use TaxonomyTidy\Infrastructure\Persistence\DatabaseTransaction;
use WP_Term;

/** Coordinates fixed targets, locks, batches, progress, and terminal states. */
final class ExecutionWorkflow {
	/** Maximum items handled by one authenticated request. */
	public const BATCH_SIZE = 10;

	/** Lock lease, renewed between items. */
	private const LOCK_TTL = 60;

	/**
	 * Creates the execution workflow.
	 *
	 * @param OperationRepository     $operations Operations.
	 * @param OperationItemRepository $items      Fixed work items.
	 * @param OperationLock           $lock       Taxonomy lease.
	 * @param PlanService             $plans      Hash and fingerprint validator.
	 * @param ItemExecutor            $executor   Single-item executor.
	 * @param DatabaseTransaction     $transaction Per-item transaction boundary.
	 */
	public function __construct(
		private readonly OperationRepository $operations,
		private readonly OperationItemRepository $items,
		private readonly OperationLock $lock,
		private readonly PlanService $plans,
		private readonly ItemExecutor $executor,
		private readonly DatabaseTransaction $transaction
	) {
	}

	/**
	 * Starts or resumes one bounded execution request.
	 *
	 * @param int         $operation_id Previewed or running operation ID.
	 * @param int         $user_id      Current administrator ID.
	 * @param Taxonomy    $taxonomy     Current screen taxonomy.
	 * @param string|null $reserved_token Preflight reservation for a combined start.
	 * @return array<string, mixed>
	 * @throws ExecutionException When authorization context, preview, state, or lock is invalid.
	 * @throws \Throwable When persistence fails or an interrupted item cannot be marked terminal.
	 */
	public function run_batch( int $operation_id, int $user_id, Taxonomy $taxonomy, ?string $reserved_token = null ): array {
		$operation = $this->operations->find( $operation_id );
		if ( null === $operation || $user_id !== (int) $operation['user_id'] || $taxonomy->value !== $operation['taxonomy'] ) {
			$this->failure( ExecutionErrorCode::INVALID_OPERATION );
		}
		$status = Status::tryFrom( (string) $operation['status'] );
		if ( ! in_array( $status, array( Status::PREVIEWED, Status::RUNNING ), true ) ) {
			$this->failure( ExecutionErrorCode::INVALID_OPERATION );
		}

		$token = $reserved_token ?? $this->lock->acquire( $operation_id, self::LOCK_TTL );
		if ( null === $token || ( null !== $reserved_token && ! $this->lock->owns( $operation_id, $token, self::LOCK_TTL ) ) ) {
			$this->failure( ExecutionErrorCode::LOCKED );
		}

		try {
			if ( Status::PREVIEWED === $status ) {
				$this->start( $operation, $taxonomy );
			}
			foreach ( $this->items->find_pending( $operation_id, self::BATCH_SIZE ) as $item ) {
				if ( ! $this->lock->renew( $operation_id, $token, self::LOCK_TTL ) ) {
					$this->failure( ExecutionErrorCode::LOCKED );
				}
				if ( ! $this->items->record_attempt( (int) $item['id'] ) ) {
					$this->failure( ExecutionErrorCode::INVALID_OPERATION );
				}
				try {
					if ( ! $this->transaction->begin() ) {
						$this->failure( ExecutionErrorCode::UPDATE_FAILED );
					}
					$result = $this->executor->execute( $item, $taxonomy );
					if ( 'skipped' === $result ) {
						$saved = $this->items->mark_skipped( (int) $item['id'], 'source_retained' );
					} else {
						$saved = $this->items->mark_completed( (int) $item['id'] );
					}
					if ( ! $saved ) {
						$this->failure( ExecutionErrorCode::INVALID_OPERATION );
					}
					if ( ! $this->transaction->commit() ) {
						$this->failure( ExecutionErrorCode::JOURNAL_FAILED );
					}
				} catch ( ExecutionException $exception ) {
					$this->transaction->rollback();
					$this->executor->record_failure( $item, $exception->error_code() );
					if ( ! $this->items->mark_failed( (int) $item['id'], $exception->error_code() ) ) {
						throw $exception;
					}
				} catch ( \Throwable $exception ) {
					$this->transaction->rollback();
					if ( ! $this->items->mark_failed( (int) $item['id'], ExecutionErrorCode::JOURNAL_FAILED ) ) {
						throw $exception;
					}
				}
			}
			$this->finish_if_ready( $operation_id );
			return $this->result( $operation_id );
		} finally {
			$this->lock->release( $operation_id, $token );
		}
	}

	/**
	 * Reserves a taxonomy lock after validating one member of a combined start.
	 *
	 * @param int      $operation_id Previewed operation ID.
	 * @param int      $user_id      Owning administrator ID.
	 * @param Taxonomy $taxonomy     Expected taxonomy.
	 * @return string Opaque lock token.
	 * @throws ExecutionException When validation or reservation fails.
	 */
	public function reserve_start( int $operation_id, int $user_id, Taxonomy $taxonomy ): string {
		$this->validate_start( $operation_id, $user_id, $taxonomy );
		$token = $this->lock->acquire( $operation_id, self::LOCK_TTL );
		if ( null === $token ) {
			$this->failure( ExecutionErrorCode::LOCKED );
		}
		return $token;
	}

	/**
	 * Releases a combined-start reservation that was not consumed by a batch.
	 *
	 * @param int    $operation_id Operation that owns the reservation.
	 * @param string $token        Reservation token.
	 */
	public function release_reservation( int $operation_id, string $token ): void {
		$this->lock->release( $operation_id, $token );
	}

	/**
	 * Checks a preview before a shared UI starts either taxonomy operation.
	 *
	 * @param int      $operation_id Previewed operation ID.
	 * @param int      $user_id      Owning administrator ID.
	 * @param Taxonomy $taxonomy     Expected taxonomy.
	 * @throws ExecutionException When the operation cannot safely start.
	 */
	public function validate_start( int $operation_id, int $user_id, Taxonomy $taxonomy ): void {
		$operation = $this->operations->find( $operation_id );
		if ( null === $operation || $user_id !== (int) $operation['user_id'] || $taxonomy->value !== $operation['taxonomy'] || Status::PREVIEWED->value !== $operation['status'] ) {
			$this->failure( ExecutionErrorCode::INVALID_OPERATION );
		}
		$requested = is_array( $operation['requested_data'] ) ? $operation['requested_data'] : array();
		$plan      = is_array( $requested['plan'] ?? null ) ? array_values( $requested['plan'] ) : array();
		$preview   = is_array( $requested['preview'] ?? null ) ? $requested['preview'] : array();
		if ( array() === $plan || count( $plan ) !== count( (array) ( $preview['items'] ?? array() ) ) ) {
			$this->failure( ExecutionErrorCode::STALE_PREVIEW );
		}
		$hash = $this->plans->plan_hash( $plan );
		if ( ! is_string( $operation['plan_hash'] ) || ! hash_equals( $operation['plan_hash'], $hash ) || ! is_string( $operation['state_fingerprint'] ) || ! $this->plans->is_current( $taxonomy, $plan, $operation['state_fingerprint'] ) ) {
			$this->failure( ExecutionErrorCode::STALE_PREVIEW );
		}
	}

	/**
	 * Returns an interrupted running record with current item counts.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @return array<string, mixed>|null
	 */
	public function latest( int $user_id, Taxonomy $taxonomy ): ?array {
		$operation = $this->operations->find_running( $user_id, $taxonomy );
		if ( null !== $operation ) {
			$operation['progress'] = $this->items->progress( (int) $operation['id'] );
		}
		return $operation;
	}

	/**
	 * Validates a preview and creates its fixed execution items.
	 *
	 * @param array<string, mixed> $operation Previewed operation.
	 * @param Taxonomy             $taxonomy  Current taxonomy.
	 * @throws ExecutionException When the preview is stale or invalid.
	 */
	private function start( array $operation, Taxonomy $taxonomy ): void {
		$this->validate_start( (int) $operation['id'], (int) $operation['user_id'], $taxonomy );
		$requested = is_array( $operation['requested_data'] ) ? $operation['requested_data'] : array();
		$plan      = is_array( $requested['plan'] ?? null ) ? array_values( $requested['plan'] ) : array();
		$preview   = is_array( $requested['preview'] ?? null ) ? $requested['preview'] : array();
		$hash      = $this->plans->plan_hash( $plan );
		if ( '' === (string) $operation['plan_hash'] || ! hash_equals( (string) $operation['plan_hash'], $hash ) || ! $this->plans->is_current( $taxonomy, $plan, (string) $operation['state_fingerprint'] ) ) {
			$this->failure( ExecutionErrorCode::STALE_PREVIEW );
		}
		$this->seed_items( (int) $operation['id'], $taxonomy, $plan, $preview );
		$this->operations->transition( (int) $operation['id'], Status::RUNNING );
	}

	/**
	 * Writes fixed work items once.
	 *
	 * @param int                        $operation_id Operation ID.
	 * @param Taxonomy                   $taxonomy     Current taxonomy.
	 * @param list<array<string, mixed>> $plan         Normalized plan.
	 * @param array<string, mixed>       $preview      Stored preview.
	 * @throws ExecutionException When preview targets are incomplete.
	 */
	private function seed_items( int $operation_id, Taxonomy $taxonomy, array $plan, array $preview ): void {
		$preview_items = is_array( $preview['items'] ?? null ) ? array_values( $preview['items'] ) : array();
		foreach ( $plan as $index => $plan_item ) {
			$action       = Action::from( (string) $plan_item['action'] );
			$preview_item = is_array( $preview_items[ $index ] ?? null ) ? $preview_items[ $index ] : array();
			foreach ( $plan_item['sources'] as $source_index => $source ) {
				$term = get_term( (int) $source['term_id'] );
				if ( ! $term instanceof WP_Term || $taxonomy->value !== $term->taxonomy ) {
					$this->failure( ExecutionErrorCode::STALE_PREVIEW );
				}
				$snapshot       = $this->term_snapshot( $term );
				$preview_source = is_array( $preview_item['sources'][ $source_index ] ?? null ) ? $preview_item['sources'][ $source_index ] : array();
				if ( Action::RENAME === $action ) {
					$after         = $snapshot;
					$after['name'] = (string) $plan_item['new_name'];
					if ( null !== $plan_item['new_slug'] ) {
						$after['slug'] = (string) $plan_item['new_slug'];
					}
					$this->items->add_once(
						$operation_id,
						sprintf( '10:rename:%d', $term->term_id ),
						$action,
						array(
							'kind'    => 'rename',
							'term_id' => $term->term_id,
							'before'  => $snapshot,
							'after'   => $after,
						)
					);
				} elseif ( Action::DELETE === $action ) {
					$this->items->add_once(
						$operation_id,
						sprintf( '10:delete:%d', $term->term_id ),
						$action,
						array(
							'kind'     => 'delete',
							'term_id'  => $term->term_id,
							'snapshot' => $snapshot,
						)
					);
				} else {
					$destination_id = (int) $plan_item['destination']['term_id'];
					$destination    = get_term( $destination_id );
					if ( ! $destination instanceof WP_Term || $taxonomy->value !== $destination->taxonomy ) {
						$this->failure( ExecutionErrorCode::STALE_PREVIEW );
					}
					$destination_snapshot = $this->term_snapshot( $destination );
					if ( ! is_array( $preview_source['target_post_ids'] ?? null ) ) {
						$this->failure( ExecutionErrorCode::STALE_PREVIEW );
					}
					foreach ( (array) ( $preview_source['target_post_ids'] ?? array() ) as $post_id ) {
						$this->items->add_once(
							$operation_id,
							sprintf( '10:merge:%d:%d', $term->term_id, (int) $post_id ),
							$action,
							array(
								'kind'                 => 'merge_post',
								'post_id'              => (int) $post_id,
								'source_id'            => $term->term_id,
								'destination_id'       => $destination_id,
								'source_snapshot'      => $snapshot,
								'destination_snapshot' => $destination_snapshot,
								'destination_present'  => has_term( $destination_id, $taxonomy->value, (int) $post_id ),
							)
						);
					}
					$this->items->add_once(
						$operation_id,
						sprintf( '20:merge-finalize:%d', $term->term_id ),
						$action,
						array(
							'kind'     => 'merge_finalize',
							'term_id'  => $term->term_id,
							'snapshot' => $snapshot,
						)
					);
				}
			}
		}
	}

	/**
	 * Completes a running operation only after all items are terminal.
	 *
	 * @param int $operation_id Operation ID.
	 */
	private function finish_if_ready( int $operation_id ): void {
		$progress = $this->items->progress( $operation_id );
		if ( 0 !== $progress['pending'] ) {
			$this->operations->save_result( $operation_id, $progress );
			return;
		}
		$status = 0 < $progress['failed'] ? ( $progress['completed'] + $progress['skipped'] > 0 ? Status::PARTIAL_FAILED : Status::FAILED ) : Status::COMPLETED;
		$this->operations->save_result( $operation_id, $progress, 0 < $progress['failed'] ? array( 'item_failures' => $progress['failed'] ) : array(), 0 < $progress['skipped'] ? array( 'source_retained' => $progress['skipped'] ) : array() );
		$this->operations->transition( $operation_id, $status );
	}

	/**
	 * Returns the operation with fresh progress counts.
	 *
	 * @param int $operation_id Operation ID.
	 * @return array<string, mixed>
	 */
	private function result( int $operation_id ): array {
		$operation             = $this->operations->find( $operation_id );
		$operation['progress'] = $this->items->progress( $operation_id );
		return $operation;
	}

	/**
	 * Snapshots the term fields needed by the journal and Undo.
	 *
	 * @param WP_Term $term Current term.
	 * @return array<string, mixed>
	 */
	private function term_snapshot( WP_Term $term ): array {
		return array(
			'term_id'          => $term->term_id,
			'term_taxonomy_id' => (int) $term->term_taxonomy_id,
			'taxonomy'         => $term->taxonomy,
			'name'             => $term->name,
			'slug'             => $term->slug,
			'description'      => $term->description,
			'parent'           => (int) $term->parent,
			'captured_at'      => current_time( 'mysql', true ),
		);
	}

	/**
	 * Throws an internal coded execution failure.
	 *
	 * @param string $code Stable execution error code.
	 * @return never
	 * @throws ExecutionException Always.
	 */
	private function failure( string $code ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal code is mapped to translated escaped UI text later.
		throw new ExecutionException( $code );
	}
}
