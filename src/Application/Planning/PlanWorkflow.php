<?php
/**
 * Draft-plan and preview workflow.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Planning;

use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;
use TaxonomyTidy\Infrastructure\Persistence\PersistenceException;

/**
 * Coordinates Phase 4 persistence without executing taxonomy changes.
 */
final class PlanWorkflow {
	/**
	 * Operation repository.
	 *
	 * @var OperationRepository
	 */
	private OperationRepository $operations;

	/**
	 * Read-only plan service.
	 *
	 * @var PlanService
	 */
	private PlanService $plans;

	/**
	 * Creates the workflow.
	 *
	 * @param OperationRepository $operations Operation persistence.
	 * @param PlanService         $plans      Plan validation and preview service.
	 */
	public function __construct( OperationRepository $operations, PlanService $plans ) {
		$this->operations = $operations;
		$this->plans      = $plans;
	}

	/**
	 * Returns the current editable operation without creating one.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @return array<string, mixed>|null
	 * @throws \JsonException       When stored JSON is invalid.
	 * @throws PersistenceException When stored data or state encoding is invalid.
	 */
	public function current( int $user_id, Taxonomy $taxonomy ): ?array {
		$operation = $this->operations->find_editable( $user_id, $taxonomy );
		if ( null !== $operation && Status::PREVIEWED->value === $operation['status'] ) {
			$fingerprint                  = is_string( $operation['state_fingerprint'] ) ? $operation['state_fingerprint'] : '';
			$operation['preview_current'] = $this->plans->is_current( $taxonomy, $this->plan_from_operation( $operation ), $fingerprint );
		}

		return $operation;
	}

	/**
	 * Adds one validated item and invalidates an older preview.
	 *
	 * @param int                  $user_id  Administrator user ID.
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Sanitized request item.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When the item conflicts with the plan.
	 * @throws \JsonException           When stored JSON is invalid.
	 * @throws PersistenceException     When the draft cannot be persisted.
	 */
	public function add( int $user_id, Taxonomy $taxonomy, array $item ): array {
		$operation = $this->operation_or_create( $user_id, $taxonomy );
		$items     = $this->plan_from_operation( $operation );
		$items[]   = $item;
		$plan      = $this->plans->normalize( $taxonomy, $items );
		$this->operations->save_draft( (int) $operation['id'], $user_id, array( 'plan' => $plan ) );

		return $this->require_current( $user_id, $taxonomy );
	}

	/**
	 * Validates one input, updates the plan, and persists its preview only after validation.
	 *
	 * Validation and preview generation happen before any operation record is created or changed.
	 * WordPress term and relationship data remain read-only throughout this workflow.
	 *
	 * @param int                  $user_id  Administrator user ID.
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Sanitized request item.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When the item or resulting plan is invalid.
	 * @throws \JsonException           When stored JSON is invalid.
	 * @throws PersistenceException     When the preview context cannot be persisted.
	 */
	public function preview_with_item( int $user_id, Taxonomy $taxonomy, array $item ): array {
		$operation = $this->current( $user_id, $taxonomy );
		$plan      = null === $operation ? array() : $this->plan_from_operation( $operation );
		$plan[]    = $item;
		$preview   = $this->plans->preview( $taxonomy, $plan );

		if ( null === $operation ) {
			$this->operations->create( $user_id, $taxonomy, array( 'plan' => array() ) );
			$operation = $this->require_current( $user_id, $taxonomy );
		}

		$this->operations->save_draft( (int) $operation['id'], $user_id, array( 'plan' => $preview['plan'] ) );
		$this->operations->save_preview_context(
			(int) $operation['id'],
			(string) $preview['plan_hash'],
			(string) $preview['state_fingerprint'],
			array(
				'plan'    => $preview['plan'],
				'preview' => $preview,
			)
		);
		$this->operations->transition( (int) $operation['id'], Status::PREVIEWED );

		return $this->require_current( $user_id, $taxonomy );
	}

	/**
	 * Removes a plan item by its displayed zero-based index.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param int      $index    Item index.
	 * @return array<string, mixed>|null
	 * @throws PlanValidationException When the index is invalid.
	 */
	public function remove( int $user_id, Taxonomy $taxonomy, int $index ): ?array {
		$operation = $this->current( $user_id, $taxonomy );
		if ( null === $operation || Status::DRAFT->value !== $operation['status'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		$plan = $this->plan_from_operation( $operation );
		if ( ! isset( $plan[ $index ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		array_splice( $plan, $index, 1 );
		$plan = array() === $plan ? array() : $this->plans->normalize( $taxonomy, $plan );
		$this->operations->save_draft( (int) $operation['id'], $user_id, array( 'plan' => $plan ) );

		return $this->current( $user_id, $taxonomy );
	}

	/**
	 * Generates and persists a preview, then moves draft to previewed.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When there is no valid draft plan.
	 */
	public function preview( int $user_id, Taxonomy $taxonomy ): array {
		$operation = $this->current( $user_id, $taxonomy );
		if ( null === $operation || Status::DRAFT->value !== $operation['status'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		$plan = $this->plan_from_operation( $operation );
		if ( array() === $plan ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		$preview = $this->plans->preview( $taxonomy, $plan );
		$this->operations->save_preview_context(
			(int) $operation['id'],
			(string) $preview['plan_hash'],
			(string) $preview['state_fingerprint'],
			array(
				'plan'    => $preview['plan'],
				'preview' => $preview,
			)
		);
		$this->operations->transition( (int) $operation['id'], Status::PREVIEWED );

		return $this->require_current( $user_id, $taxonomy );
	}

	/**
	 * Reopens a preview as a draft and invalidates its hashes.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When no preview exists.
	 */
	public function revise( int $user_id, Taxonomy $taxonomy ): array {
		$operation = $this->current( $user_id, $taxonomy );
		if ( null === $operation || Status::PREVIEWED->value !== $operation['status'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		$this->operations->save_draft(
			(int) $operation['id'],
			$user_id,
			array( 'plan' => $this->plan_from_operation( $operation ) )
		);

		return $this->require_current( $user_id, $taxonomy );
	}

	/**
	 * Discards the current draft or preview.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @throws \JsonException       When stored JSON is invalid.
	 * @throws PersistenceException When the plan cannot be discarded.
	 */
	public function discard( int $user_id, Taxonomy $taxonomy ): void {
		$operation = $this->current( $user_id, $taxonomy );
		if ( null !== $operation ) {
			$this->operations->discard( (int) $operation['id'], $user_id );
		}
	}

	/**
	 * Creates a draft only when none exists.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @return array<string, mixed>
	 * @throws \JsonException       When stored JSON is invalid.
	 * @throws PersistenceException When a draft cannot be created.
	 */
	private function operation_or_create( int $user_id, Taxonomy $taxonomy ): array {
		$operation = $this->current( $user_id, $taxonomy );
		if ( null === $operation ) {
			$this->operations->create( $user_id, $taxonomy, array( 'plan' => array() ) );
			$operation = $this->require_current( $user_id, $taxonomy );
		}
		return $operation;
	}

	/**
	 * Returns the current operation or fails after an expected write.
	 *
	 * @param int      $user_id  Administrator user ID.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When the expected operation cannot be loaded.
	 */
	private function require_current( int $user_id, Taxonomy $taxonomy ): array {
		$operation = $this->current( $user_id, $taxonomy );
		if ( null === $operation ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::PLAN_INVALID ) );
		}
		return $operation;
	}

	/**
	 * Extracts a stored plan while ignoring preview presentation data.
	 *
	 * @param array<string, mixed> $operation Stored operation.
	 * @return list<array<string, mixed>>
	 */
	private function plan_from_operation( array $operation ): array {
		$plan = $operation['requested_data']['plan'] ?? array();
		return is_array( $plan ) ? array_values( $plan ) : array();
	}
}
