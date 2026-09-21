<?php
/**
 * Current-state Undo assessment and preview creation.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Undo;

use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\ChangeJournalRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationItemRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationLock;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;
use WP_Post;
use WP_Term;

/** Builds an inverse plan only from actual, still-current journaled changes. */
final class UndoPlanner {
	private const LOCK_TTL = 60;

	/** Journal types that represent reversible mutations. */
	private const REVERSIBLE = array(
		'name_changed',
		'slug_changed',
		'destination_added',
		'source_removed',
		'source_deleted',
		'term_deleted',
	);

	/**
	 * Creates the journal-based current-state planner.
	 *
	 * @param OperationRepository     $operations Operation storage.
	 * @param OperationItemRepository $items      Original fixed items.
	 * @param ChangeJournalRepository $journal    Actual changes.
	 * @param OperationLock           $lock       Original-operation preview lease.
	 */
	public function __construct(
		private readonly OperationRepository $operations,
		private readonly OperationItemRepository $items,
		private readonly ChangeJournalRepository $journal,
		private readonly OperationLock $lock
	) {
	}

	/**
	 * Creates a separate, hidden-until-started Undo preview operation.
	 *
	 * @param int $original_id Original operation ID.
	 * @param int $user_id     Current administrator ID.
	 * @return array<string, mixed>
	 */
	public function preview( int $original_id, int $user_id ): array {
		$assessment = $this->assess( $original_id, $user_id );
		if ( 'none' === $assessment['availability'] ) {
			$this->failure_for_existing_or( $original_id, $user_id, UndoErrorCode::NOT_AVAILABLE );
		}
		$token = $this->lock->acquire_undo_parent( $original_id, self::LOCK_TTL );
		if ( null === $token ) {
			$this->failure( UndoErrorCode::LOCKED );
		}
		try {
			// The pre-lock assessment is only an early validation. This second
			// assessment and child lookup are the authoritative atomic section.
			$assessment = $this->assess( $original_id, $user_id );
			if ( 'none' === $assessment['availability'] ) {
				$this->failure_for_existing_or( $original_id, $user_id, UndoErrorCode::NOT_AVAILABLE );
			}
			$existing = $this->operations->undos( $original_id );
			if ( array() !== $existing ) {
				return $this->reuse_preview_or_fail( $existing, $assessment, $user_id );
			}
			$taxonomy = Taxonomy::from( (string) $assessment['taxonomy'] );
			$undo_id  = $this->operations->create(
				$user_id,
				$taxonomy,
				array(
					'kind'                  => 'undo',
					'original_operation_id' => $original_id,
					'preview'               => $assessment,
				),
				$original_id
			);
			$this->operations->save_preview_context(
				$undo_id,
				$this->plan_hash( (array) $assessment['items'] ),
				(string) $assessment['fingerprint'],
				array(
					'kind'                  => 'undo',
					'original_operation_id' => $original_id,
					'preview'               => $assessment,
				)
			);
			$this->operations->transition( $undo_id, Status::UNDO_PREVIEWED );
			return $this->operations->find( $undo_id ) ?? array();
		} finally {
			$this->lock->release( $original_id, $token );
		}
	}

	/**
	 * Reuses one matching preview or rejects an existing/legacy Undo safely.
	 *
	 * @param list<array<string, mixed>> $existing   Existing child operations.
	 * @param array<string, mixed>       $assessment Fresh current-state assessment.
	 * @param int                        $user_id     Current administrator ID.
	 * @return array<string, mixed>
	 */
	private function reuse_preview_or_fail( array $existing, array $assessment, int $user_id ): array {
		if ( 1 !== count( $existing ) ) {
			$this->failure( UndoErrorCode::DUPLICATE );
		}
		$undo = $existing[0];
		if ( $user_id !== (int) $undo['user_id'] || 'undo' !== ( $undo['requested_data']['kind'] ?? '' ) || (string) $assessment['taxonomy'] !== $undo['taxonomy'] ) {
			$this->failure( UndoErrorCode::DUPLICATE );
		}
		$status = Status::tryFrom( (string) $undo['status'] );
		if ( Status::UNDO_PREVIEWED === $status ) {
			$stored = is_array( $undo['requested_data']['preview'] ?? null ) ? $undo['requested_data']['preview'] : array();
			if ( ! is_string( $undo['plan_hash'] ) || ! hash_equals( $undo['plan_hash'], $this->plan_hash( (array) $assessment['items'] ) ) || ! is_string( $undo['state_fingerprint'] ) || ! hash_equals( $undo['state_fingerprint'], (string) $assessment['fingerprint'] ) || (string) ( $stored['fingerprint'] ?? '' ) !== (string) $assessment['fingerprint'] ) {
				$this->failure( UndoErrorCode::STALE_PREVIEW );
			}
			return $undo;
		}
		$this->failure_for_status( $status );
	}

	/**
	 * Preserves a specific existing-Undo reason without exposing another owner.
	 *
	 * @param int    $original_id Original operation ID.
	 * @param int    $user_id     Current administrator ID.
	 * @param string $fallback    Error used when no child explains the failure.
	 * @return never
	 */
	private function failure_for_existing_or( int $original_id, int $user_id, string $fallback ): never {
		$existing = $this->operations->undos( $original_id );
		if ( 1 !== count( $existing ) || (int) ( $existing[0]['user_id'] ?? 0 ) !== $user_id || 'undo' !== ( $existing[0]['requested_data']['kind'] ?? '' ) ) {
			$this->failure( array() === $existing ? $fallback : UndoErrorCode::DUPLICATE );
		}
		$this->failure_for_status( Status::tryFrom( (string) $existing[0]['status'] ) );
	}

	/**
	 * Throws the safe user-facing reason for an existing child status.
	 *
	 * @param Status|null $status Existing child status, or null when invalid.
	 */
	private function failure_for_status( ?Status $status ): never {
		$code = match ( $status ) {
			Status::UNDOING => UndoErrorCode::IN_PROGRESS,
			Status::UNDONE => UndoErrorCode::ALREADY_UNDONE,
			Status::UNDO_PARTIAL_FAILED, Status::FAILED => UndoErrorCode::NOT_RESUMABLE,
			Status::UNDO_PREVIEWED => UndoErrorCode::STALE_PREVIEW,
			default => UndoErrorCode::DUPLICATE,
		};
		$this->failure( $code );
	}

	/**
	 * Recalculates whether the original operation can be safely reversed now.
	 *
	 * @param int      $original_id Original operation ID.
	 * @param int      $user_id     Current administrator ID.
	 * @param int|null $ignore_undo_id Preview being validated before start.
	 * @return array<string, mixed>
	 */
	public function assess( int $original_id, int $user_id, ?int $ignore_undo_id = null ): array {
		$operation = $this->operations->find_owned( $original_id, $user_id );
		if ( null === $operation || null !== $operation['parent_operation_id'] ) {
			return $this->unavailable( $original_id, '', 'invalid_operation' );
		}
		$status = Status::tryFrom( (string) $operation['status'] );
		if ( ! in_array( $status, array( Status::COMPLETED, Status::PARTIAL_FAILED ), true ) ) {
			return $this->unavailable( $original_id, (string) $operation['taxonomy'], 'status_not_undoable' );
		}
		foreach ( $this->operations->started_undos( $original_id ) as $undo ) {
			if ( $ignore_undo_id !== (int) $undo['id'] ) {
				return $this->unavailable( $original_id, (string) $operation['taxonomy'], 'undo_already_started' );
			}
		}

		$taxonomy       = Taxonomy::from( (string) $operation['taxonomy'] );
		$original_items = array();
		foreach ( $this->items->find_for_operation( $original_id ) as $item ) {
			$original_items[ (int) $item['id'] ] = $item;
		}
		$changes = array_values(
			array_filter(
				$this->journal->find_for_operation( $original_id ),
				static fn( array $change ): bool => null === $change['undone_at'] && in_array( $change['change_type'], self::REVERSIBLE, true )
			)
		);
		if ( array() === $changes ) {
			return $this->unavailable( $original_id, $taxonomy->value, 'journal_missing' );
		}

		$planned_deleted = array();
		$safe            = array();
		$conflicts       = array();
		$merge_changes   = array();
		foreach ( $changes as $change ) {
			$type = (string) $change['change_type'];
			if ( in_array( $type, array( 'source_deleted', 'term_deleted' ), true ) ) {
				$snapshot = is_array( $change['before_data'] ) ? $change['before_data'] : array();
				$term_id  = (int) ( $snapshot['term_id'] ?? 0 );
				$problem  = $this->term_restore_conflict( $snapshot, $taxonomy );
				if ( null === $problem ) {
					$planned_deleted[ $term_id ] = true;
					$safe[]                      = array(
						'kind'                => 'restore_term',
						'original_change_ids' => array( (int) $change['id'] ),
						'original_term_id'    => $term_id,
						'snapshot'            => $snapshot,
					);
				} else {
					$conflicts[] = $this->conflict(
						$type,
						$term_id,
						$problem,
						array(
							'name'   => (string) ( $snapshot['name'] ?? '' ),
							'slug'   => (string) ( $snapshot['slug'] ?? '' ),
							'parent' => (int) ( $snapshot['parent'] ?? 0 ),
						),
						$this->term_state( $term_id, $taxonomy, (string) ( $snapshot['slug'] ?? '' ), (string) ( $snapshot['name'] ?? '' ) )
					);
				}
			} elseif ( in_array( $type, array( 'name_changed', 'slug_changed' ), true ) ) {
				$field   = 'name_changed' === $type ? 'name' : 'slug';
				$term_id = (int) ( $change['object_id'] ?? 0 );
				$before  = (string) ( $change['before_data'][ $field ] ?? '' );
				$after   = (string) ( $change['after_data'][ $field ] ?? '' );
				$problem = $this->rename_conflict( $term_id, $taxonomy, $field, $before, $after );
				if ( null === $problem ) {
					$safe[] = array(
						'kind'                => 'undo_rename',
						'field'               => $field,
						'term_id'             => $term_id,
						'expected'            => $after,
						'restore'             => $before,
						'original_change_ids' => array( (int) $change['id'] ),
					);
				} else {
					$term        = get_term( $term_id, $taxonomy->value );
					$conflicts[] = $this->conflict(
						$type,
						$term_id,
						$problem,
						array( $field => $before ),
						$term instanceof WP_Term ? array( $field => (string) $term->{$field} ) : array( 'missing' => true )
					);
				}
			} else {
				$merge_changes[ (int) $change['item_id'] ][] = $change;
			}
		}

		foreach ( $merge_changes as $item_id => $item_changes ) {
			$original_item = $original_items[ $item_id ] ?? null;
			$payload       = is_array( $original_item['payload'] ?? null ) ? $original_item['payload'] : array();
			$problem       = $this->merge_conflict( $payload, $taxonomy, $planned_deleted );
			if ( null !== $problem ) {
				$conflicts[] = $this->conflict(
					'merge_relationship',
					(int) ( $payload['post_id'] ?? 0 ),
					$problem,
					array(
						'source_assigned'      => true,
						'destination_assigned' => (bool) ( $payload['destination_present'] ?? false ),
					),
					$this->relationship_state( $payload, $taxonomy )
				);
				continue;
			}
			$types  = array_column( $item_changes, 'change_type' );
			$safe[] = array(
				'kind'                 => 'undo_merge_post',
				'post_id'              => (int) $payload['post_id'],
				'original_source_id'   => (int) $payload['source_id'],
				'destination_id'       => (int) $payload['destination_id'],
				'source_snapshot'      => (array) $payload['source_snapshot'],
				'destination_snapshot' => (array) $payload['destination_snapshot'],
				'remove_destination'   => in_array( 'destination_added', $types, true ),
				'original_change_ids'  => array_values( array_map( static fn( array $change ): int => (int) $change['id'], $item_changes ) ),
			);
		}

		$availability = array() === $safe ? 'none' : ( array() === $conflicts ? 'full' : 'partial' );
		$reason       = match ( $availability ) {
			'full' => 'state_matches',
			'partial' => 'some_conflicts',
			default => 'all_conflicted',
		};
		$assessment                = array(
			'original_operation_id' => $original_id,
			'taxonomy'              => $taxonomy->value,
			'availability'          => $availability,
			'reason'                => $reason,
			'items'                 => $safe,
			'conflicts'             => $conflicts,
			'restore_terms'         => count( array_filter( $safe, static fn( array $item ): bool => 'restore_term' === $item['kind'] ) ),
			'restore_assignments'   => count( array_filter( $safe, static fn( array $item ): bool => 'undo_merge_post' === $item['kind'] ) ),
			'remove_assignments'    => count( array_filter( $safe, static fn( array $item ): bool => 'undo_merge_post' === $item['kind'] && true === $item['remove_destination'] ) ),
		);
		$assessment['fingerprint'] = $this->fingerprint( $assessment );
		return $assessment;
	}

	/**
	 * Hashes a fixed inverse plan.
	 *
	 * @param list<array<string, mixed>> $items Inverse items.
	 */
	public function plan_hash( array $items ): string {
		return hash( 'sha256', (string) wp_json_encode( $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Hashes all current-state facts used by a preview.
	 *
	 * @param array<string, mixed> $assessment Undo assessment.
	 */
	private function fingerprint( array $assessment ): string {
		unset( $assessment['fingerprint'] );
		return hash( 'sha256', (string) wp_json_encode( $assessment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Returns the first conflict preventing safe term recreation.
	 *
	 * @param array<string, mixed> $snapshot Deleted term snapshot.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 */
	private function term_restore_conflict( array $snapshot, Taxonomy $taxonomy ): ?string {
		if ( (string) ( $snapshot['taxonomy'] ?? '' ) !== $taxonomy->value || '' === (string) ( $snapshot['name'] ?? '' ) || '' === (string) ( $snapshot['slug'] ?? '' ) ) {
			return 'snapshot_incomplete';
		}
		if ( get_term( (int) ( $snapshot['term_id'] ?? 0 ) ) instanceof WP_Term || get_term_by( 'slug', (string) $snapshot['slug'], $taxonomy->value ) instanceof WP_Term || get_term_by( 'name', (string) $snapshot['name'], $taxonomy->value ) instanceof WP_Term ) {
			return 'term_or_slug_exists';
		}
		$parent = (int) ( $snapshot['parent'] ?? 0 );
		if ( Taxonomy::CATEGORY === $taxonomy && 0 !== $parent ) {
			$parent_term = get_term( $parent, $taxonomy->value );
			if ( ! $parent_term instanceof WP_Term ) {
				return 'parent_missing';
			}
		}
		return null;
	}

	/**
	 * Returns a conflict when a journaled rename no longer matches current data.
	 *
	 * @param int      $term_id  Term ID.
	 * @param Taxonomy $taxonomy Expected taxonomy.
	 * @param string   $field    Name or slug.
	 * @param string   $before   Value to restore.
	 * @param string   $after    Value applied by the original operation.
	 */
	private function rename_conflict( int $term_id, Taxonomy $taxonomy, string $field, string $before, string $after ): ?string {
		$term = get_term( $term_id, $taxonomy->value );
		if ( ! $term instanceof WP_Term ) {
			return 'term_missing';
		}
		if ( $after !== (string) $term->{$field} ) {
			return 'value_changed';
		}
		$match = get_term_by( $field, $before, $taxonomy->value );
		if ( $match instanceof WP_Term && $match->term_id !== $term_id ) {
			return 'value_conflict';
		}
		return null;
	}

	/**
	 * Returns a conflict for a journaled merge relationship.
	 *
	 * @param array<string, mixed> $payload         Original fixed item payload.
	 * @param Taxonomy             $taxonomy        Expected taxonomy.
	 * @param array<int, bool>     $planned_deleted Terms that will be recreated first.
	 */
	private function merge_conflict( array $payload, Taxonomy $taxonomy, array $planned_deleted ): ?string {
		$post = get_post( (int) ( $payload['post_id'] ?? 0 ) );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return 'post_missing_or_changed';
		}
		$source_id = (int) ( $payload['source_id'] ?? 0 );
		if ( ! isset( $planned_deleted[ $source_id ] ) ) {
			$source = get_term( $source_id, $taxonomy->value );
			if ( ! $source instanceof WP_Term || ! $this->matches_snapshot( $source, (array) ( $payload['source_snapshot'] ?? array() ) ) ) {
				return 'source_changed';
			}
		}
		$destination = get_term( (int) ( $payload['destination_id'] ?? 0 ), $taxonomy->value );
		if ( ! $destination instanceof WP_Term || ! $this->matches_snapshot( $destination, (array) ( $payload['destination_snapshot'] ?? array() ) ) ) {
			return 'destination_changed';
		}
		if ( has_term( $source_id, $taxonomy->value, $post->ID ) || ! has_term( $destination->term_id, $taxonomy->value, $post->ID ) ) {
			return 'assignment_changed';
		}
		return null;
	}

	/**
	 * Compares relevant term fields with an immutable snapshot.
	 *
	 * @param WP_Term              $term     Current term.
	 * @param array<string, mixed> $snapshot Original snapshot.
	 */
	private function matches_snapshot( WP_Term $term, array $snapshot ): bool {
		return (string) ( $snapshot['taxonomy'] ?? '' ) === $term->taxonomy
			&& (string) ( $snapshot['name'] ?? '' ) === $term->name
			&& (string) ( $snapshot['slug'] ?? '' ) === $term->slug
			&& (int) ( $snapshot['parent'] ?? -1 ) === (int) $term->parent;
	}

	/**
	 * Creates a safe conflict description.
	 *
	 * @param string               $type      Change type.
	 * @param int                  $object_id Related object ID.
	 * @param string               $reason    Stable reason code.
	 * @param array<string, mixed> $planned   Intended restored state.
	 * @param array<string, mixed> $current   Current preserved state.
	 * @return array<string, mixed>
	 */
	private function conflict( string $type, int $object_id, string $reason, array $planned, array $current ): array {
		return array(
			'type'      => $type,
			'object_id' => $object_id,
			'reason'    => $reason,
			'planned'   => $planned,
			'current'   => $current,
			'retryable' => true,
		);
	}

	/**
	 * Captures current term and collision state for the audit record.
	 *
	 * @param int      $term_id  Original term ID.
	 * @param Taxonomy $taxonomy Expected taxonomy.
	 * @param string   $slug     Slug to restore.
	 * @param string   $name     Name to restore.
	 * @return array<string, mixed>
	 */
	private function term_state( int $term_id, Taxonomy $taxonomy, string $slug, string $name ): array {
		$term           = get_term( $term_id, $taxonomy->value );
		$slug_collision = get_term_by( 'slug', $slug, $taxonomy->value );
		$name_collision = get_term_by( 'name', $name, $taxonomy->value );
		return array(
			'term_exists'       => $term instanceof WP_Term,
			'slug_in_use'       => $slug_collision instanceof WP_Term,
			'name_in_use'       => $name_collision instanceof WP_Term,
			'collision_term_id' => $slug_collision instanceof WP_Term ? $slug_collision->term_id : ( $name_collision instanceof WP_Term ? $name_collision->term_id : null ),
		);
	}

	/**
	 * Captures current post and relationship state for a conflict record.
	 *
	 * @param array<string, mixed> $payload  Original merge item.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 * @return array<string, mixed>
	 */
	private function relationship_state( array $payload, Taxonomy $taxonomy ): array {
		$post_id = (int) ( $payload['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		return array(
			'post_exists'          => $post instanceof WP_Post,
			'published_post'       => $post instanceof WP_Post && 'post' === $post->post_type && 'publish' === $post->post_status,
			'source_assigned'      => has_term( (int) ( $payload['source_id'] ?? 0 ), $taxonomy->value, $post_id ),
			'destination_assigned' => has_term( (int) ( $payload['destination_id'] ?? 0 ), $taxonomy->value, $post_id ),
		);
	}

	/**
	 * Creates a complete unavailable assessment.
	 *
	 * @param int    $original_id Original operation ID.
	 * @param string $taxonomy    Taxonomy value when known.
	 * @param string $reason      Stable reason code.
	 * @return array<string, mixed>
	 */
	private function unavailable( int $original_id, string $taxonomy, string $reason ): array {
		$assessment                = array(
			'original_operation_id' => $original_id,
			'taxonomy'              => $taxonomy,
			'availability'          => 'none',
			'reason'                => $reason,
			'items'                 => array(),
			'conflicts'             => array(),
			'restore_terms'         => 0,
			'restore_assignments'   => 0,
			'remove_assignments'    => 0,
		);
		$assessment['fingerprint'] = $this->fingerprint( $assessment );
		return $assessment;
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
