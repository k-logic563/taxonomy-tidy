<?php
/**
 * Executes one fixed inverse item.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Application\Undo;

use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Infrastructure\Persistence\ChangeJournalRepository;
use WP_Error;
use WP_Post;
use WP_Term;

/** Applies only the exact inverse of journaled changes. */
final class UndoItemExecutor {
	/**
	 * Creates an executor backed by the immutable change journal.
	 *
	 * @param ChangeJournalRepository $journal Actual-change journal.
	 */
	public function __construct( private readonly ChangeJournalRepository $journal ) {
	}

	/**
	 * Applies one inverse item.
	 *
	 * @param array<string, mixed> $item Persisted Undo item.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 */
	public function execute( array $item, Taxonomy $taxonomy ): void {
		$payload = is_array( $item['payload'] ?? null ) ? $item['payload'] : array();
		match ( (string) ( $payload['kind'] ?? '' ) ) {
			'restore_term'    => $this->restore_term( $item, $payload, $taxonomy ),
			'undo_rename'     => $this->undo_rename( $item, $payload, $taxonomy ),
			'undo_merge_post' => $this->undo_merge_post( $item, $payload, $taxonomy ),
			default           => $this->failure( UndoErrorCode::INVALID_OPERATION ),
		};
	}

	/**
	 * Safely recreates a deleted term and records its new ID.
	 *
	 * @param array<string, mixed> $item     Undo item.
	 * @param array<string, mixed> $payload  Fixed inverse payload.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 */
	private function restore_term( array $item, array $payload, Taxonomy $taxonomy ): void {
		$snapshot = is_array( $payload['snapshot'] ?? null ) ? $payload['snapshot'] : array();
		$old_id   = (int) ( $payload['original_term_id'] ?? 0 );
		if ( (string) ( $snapshot['taxonomy'] ?? '' ) !== $taxonomy->value || get_term( $old_id ) instanceof WP_Term || get_term_by( 'slug', (string) ( $snapshot['slug'] ?? '' ), $taxonomy->value ) instanceof WP_Term || get_term_by( 'name', (string) ( $snapshot['name'] ?? '' ), $taxonomy->value ) instanceof WP_Term ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		$parent = Taxonomy::CATEGORY === $taxonomy ? (int) ( $snapshot['parent'] ?? 0 ) : 0;
		if ( 0 !== $parent && ! get_term( $parent, $taxonomy->value ) instanceof WP_Term ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		$result = wp_insert_term(
			(string) ( $snapshot['name'] ?? '' ),
			$taxonomy->value,
			array(
				'slug'        => (string) ( $snapshot['slug'] ?? '' ),
				'description' => (string) ( $snapshot['description'] ?? '' ),
				'parent'      => $parent,
			)
		);
		if ( $result instanceof WP_Error ) {
			$this->failure( UndoErrorCode::UPDATE_FAILED );
		}
		$new_id = (int) $result['term_id'];
		$term   = get_term( $new_id, $taxonomy->value );
		if ( ! $term instanceof WP_Term || ! $this->matches_snapshot( $term, $snapshot, true ) || (string) ( $snapshot['description'] ?? '' ) !== $term->description ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		$this->record(
			$item,
			'term:' . $old_id . ':restored',
			'term_restored',
			array(
				'original_term_id' => $old_id,
				'deleted'          => true,
			),
			array_merge(
				$snapshot,
				array(
					'term_id'          => $new_id,
					'original_term_id' => $old_id,
				)
			),
			$new_id
		);
		$this->mark_original_changes( $payload );
	}

	/**
	 * Restores one changed name or slug without touching the other field.
	 *
	 * @param array<string, mixed> $item     Undo item.
	 * @param array<string, mixed> $payload  Fixed inverse payload.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 */
	private function undo_rename( array $item, array $payload, Taxonomy $taxonomy ): void {
		$term_id  = (int) ( $payload['term_id'] ?? 0 );
		$field    = (string) ( $payload['field'] ?? '' );
		$expected = (string) ( $payload['expected'] ?? '' );
		$restore  = (string) ( $payload['restore'] ?? '' );
		if ( ! in_array( $field, array( 'name', 'slug' ), true ) ) {
			$this->failure( UndoErrorCode::INVALID_OPERATION );
		}
		$term = get_term( $term_id, $taxonomy->value );
		if ( ! $term instanceof WP_Term || $expected !== (string) $term->{$field} ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		$match = get_term_by( $field, $restore, $taxonomy->value );
		if ( $match instanceof WP_Term && $match->term_id !== $term_id ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		$result = wp_update_term( $term_id, $taxonomy->value, array( $field => $restore ) );
		if ( $result instanceof WP_Error ) {
			$this->failure( UndoErrorCode::UPDATE_FAILED );
		}
		$updated = get_term( $term_id, $taxonomy->value );
		if ( ! $updated instanceof WP_Term || $restore !== (string) $updated->{$field} ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		$this->record(
			$item,
			'term:' . $term_id . ':' . $field,
			$field . '_restored',
			array( $field => $expected ),
			array( $field => $restore ),
			$term_id
		);
		$this->mark_original_changes( $payload );
	}

	/**
	 * Restores one source assignment and conditionally removes its destination.
	 *
	 * @param array<string, mixed> $item     Undo item.
	 * @param array<string, mixed> $payload  Fixed inverse payload.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 */
	private function undo_merge_post( array $item, array $payload, Taxonomy $taxonomy ): void {
		$post_id        = (int) ( $payload['post_id'] ?? 0 );
		$source_id      = $this->source_id( (int) $item['operation_id'], $payload, $taxonomy );
		$destination_id = (int) ( $payload['destination_id'] ?? 0 );
		$post           = get_post( $post_id );
		$source         = get_term( $source_id, $taxonomy->value );
		$destination    = get_term( $destination_id, $taxonomy->value );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status || ! $source instanceof WP_Term || ! $destination instanceof WP_Term ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		if ( ! $this->matches_snapshot( $source, (array) ( $payload['source_snapshot'] ?? array() ), true ) || ! $this->matches_snapshot( $destination, (array) ( $payload['destination_snapshot'] ?? array() ), false ) ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		if ( has_term( $source_id, $taxonomy->value, $post_id ) || ! has_term( $destination_id, $taxonomy->value, $post_id ) ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		$result = wp_set_object_terms( $post_id, array( $source_id ), $taxonomy->value, true );
		if ( $result instanceof WP_Error ) {
			$this->failure( UndoErrorCode::UPDATE_FAILED );
		}
		$this->record(
			$item,
			'post:' . $post_id . ':source',
			'source_restored',
			array( 'assigned' => false ),
			array(
				'assigned' => true,
				'term_id'  => $source_id,
			),
			$post_id
		);
		if ( true === ( $payload['remove_destination'] ?? false ) ) {
			$result = wp_remove_object_terms( $post_id, array( $destination_id ), $taxonomy->value );
			if ( $result instanceof WP_Error || false === $result ) {
				$this->failure( UndoErrorCode::UPDATE_FAILED );
			}
			$this->record( $item, 'post:' . $post_id . ':destination', 'destination_removed', array( 'assigned' => true ), array( 'assigned' => false ), $post_id );
		}
		$this->mark_original_changes( $payload );
	}

	/**
	 * Resolves an existing or newly recreated source term ID.
	 *
	 * @param int                  $undo_id  Undo operation ID.
	 * @param array<string, mixed> $payload  Fixed inverse payload.
	 * @param Taxonomy             $taxonomy Expected taxonomy.
	 */
	private function source_id( int $undo_id, array $payload, Taxonomy $taxonomy ): int {
		$old_id = (int) ( $payload['original_source_id'] ?? 0 );
		$term   = get_term( $old_id, $taxonomy->value );
		if ( $term instanceof WP_Term ) {
			return $old_id;
		}
		$restored_id = $this->journal->restored_term_id( $undo_id, $old_id );
		if ( null === $restored_id ) {
			$this->failure( UndoErrorCode::CONFLICT );
		}
		return $restored_id;
	}

	/**
	 * Compares current term fields with the original immutable snapshot.
	 *
	 * @param WP_Term              $term         Current term.
	 * @param array<string, mixed> $snapshot     Original snapshot.
	 * @param bool                 $allow_new_id Whether recreation may change the ID.
	 */
	private function matches_snapshot( WP_Term $term, array $snapshot, bool $allow_new_id ): bool {
		return ( $allow_new_id || (int) ( $snapshot['term_id'] ?? 0 ) === $term->term_id )
			&& (string) ( $snapshot['taxonomy'] ?? '' ) === $term->taxonomy
			&& (string) ( $snapshot['name'] ?? '' ) === $term->name
			&& (string) ( $snapshot['slug'] ?? '' ) === $term->slug
			&& (int) ( $snapshot['parent'] ?? -1 ) === (int) $term->parent;
	}

	/**
	 * Marks every original journal entry reversed by the current item.
	 *
	 * @param array<string, mixed> $payload Fixed inverse payload.
	 */
	private function mark_original_changes( array $payload ): void {
		$original_id = 0;
		foreach ( (array) ( $payload['original_change_ids'] ?? array() ) as $change_id ) {
			if ( 0 === $original_id ) {
				$original_id = (int) ( $payload['original_operation_id'] ?? 0 );
			}
			if ( 0 === $original_id || ! $this->journal->mark_undone_for_operation( (int) $change_id, $original_id ) ) {
				$this->failure( UndoErrorCode::JOURNAL_FAILED );
			}
		}
	}

	/**
	 * Records one actual inverse change idempotently.
	 *
	 * @param array<string, mixed>      $item      Undo item.
	 * @param string                    $suffix    Stable key suffix.
	 * @param string                    $type      Inverse change type.
	 * @param array<string, mixed>|null $before    State before Undo.
	 * @param array<string, mixed>|null $after     State after Undo.
	 * @param int|null                  $object_id Affected WordPress object.
	 */
	private function record( array $item, string $suffix, string $type, ?array $before, ?array $after, ?int $object_id ): void {
		$this->journal->record_once( (int) $item['operation_id'], (int) $item['id'], (string) $item['item_key'] . ':' . $suffix, $type, $before, $after, $object_id );
	}

	/**
	 * Records a safe item failure code.
	 *
	 * @param array<string, mixed> $item Undo item.
	 * @param string               $code Stable error code.
	 * @param Taxonomy             $taxonomy Operation taxonomy.
	 */
	public function record_failure( array $item, string $code, Taxonomy $taxonomy ): void {
		$payload = is_array( $item['payload'] ?? null ) ? $item['payload'] : array();
		$this->record(
			$item,
			'failure',
			'undo_item_failed',
			array( 'planned' => $this->planned_state( $payload ) ),
			array(
				'error_code' => $code,
				'current'    => $this->current_state( (int) $item['operation_id'], $payload, $taxonomy ),
				'retryable'  => UndoErrorCode::CONFLICT === $code,
			),
			null
		);
	}

	/**
	 * Returns the intended state for a failed inverse item.
	 *
	 * @param array<string, mixed> $payload Fixed inverse payload.
	 * @return array<string, mixed>
	 */
	private function planned_state( array $payload ): array {
		return match ( (string) ( $payload['kind'] ?? '' ) ) {
			'undo_rename' => array( (string) ( $payload['field'] ?? '' ) => (string) ( $payload['restore'] ?? '' ) ),
			'restore_term' => (array) ( $payload['snapshot'] ?? array() ),
			'undo_merge_post' => array(
				'source_assigned'      => true,
				'destination_assigned' => ! (bool) ( $payload['remove_destination'] ?? false ),
			),
			default => array(),
		};
	}

	/**
	 * Captures current data that caused a runtime conflict.
	 *
	 * @param int                  $undo_id  Undo operation ID.
	 * @param array<string, mixed> $payload  Fixed inverse payload.
	 * @param Taxonomy             $taxonomy Operation taxonomy.
	 * @return array<string, mixed>
	 */
	private function current_state( int $undo_id, array $payload, Taxonomy $taxonomy ): array {
		$kind = (string) ( $payload['kind'] ?? '' );
		if ( 'undo_rename' === $kind ) {
			$term  = get_term( (int) ( $payload['term_id'] ?? 0 ), $taxonomy->value );
			$field = (string) ( $payload['field'] ?? '' );
			return $term instanceof WP_Term && in_array( $field, array( 'name', 'slug' ), true ) ? array( $field => (string) $term->{$field} ) : array( 'missing' => true );
		}
		if ( 'restore_term' === $kind ) {
			$snapshot = (array) ( $payload['snapshot'] ?? array() );
			$slug     = get_term_by( 'slug', (string) ( $snapshot['slug'] ?? '' ), $taxonomy->value );
			$name     = get_term_by( 'name', (string) ( $snapshot['name'] ?? '' ), $taxonomy->value );
			return array(
				'original_id_exists' => get_term( (int) ( $payload['original_term_id'] ?? 0 ) ) instanceof WP_Term,
				'slug_in_use'        => $slug instanceof WP_Term,
				'name_in_use'        => $name instanceof WP_Term,
			);
		}
		$post_id = (int) ( $payload['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		$source  = (int) ( $payload['original_source_id'] ?? 0 );
		if ( ! get_term( $source, $taxonomy->value ) instanceof WP_Term ) {
			$source = $this->journal->restored_term_id( $undo_id, $source ) ?? $source;
		}
		return array(
			'post_exists'          => $post instanceof WP_Post,
			'published_post'       => $post instanceof WP_Post && 'post' === $post->post_type && 'publish' === $post->post_status,
			'source_assigned'      => has_term( $source, $taxonomy->value, $post_id ),
			'destination_assigned' => has_term( (int) ( $payload['destination_id'] ?? 0 ), $taxonomy->value, $post_id ),
		);
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
