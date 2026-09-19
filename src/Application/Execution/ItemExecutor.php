<?php
/**
 * Executes one fixed operation item.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Execution;

use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\ChangeJournalRepository;
use TaxonomyTidy\Infrastructure\Persistence\PersistenceException;
use WP_Error;
use WP_Post;
use WP_Term;

/** Applies a single retry-safe mutation through WordPress APIs. */
final class ItemExecutor {
	/**
	 * Creates the item executor.
	 *
	 * @param ChangeJournalRepository $journal Actual-change journal.
	 */
	public function __construct( private readonly ChangeJournalRepository $journal ) {
	}

	/**
	 * Executes one persisted item.
	 *
	 * @param array<string, mixed> $item     Persisted operation item.
	 * @param Taxonomy             $taxonomy Operation taxonomy.
	 * @return string completed or skipped.
	 * @throws ExecutionException When current data conflicts or a mutation fails; persistence failures propagate.
	 */
	public function execute( array $item, Taxonomy $taxonomy ): string {
		$payload = is_array( $item['payload'] ?? null ) ? $item['payload'] : array();
		return match ( (string) ( $payload['kind'] ?? '' ) ) {
			'rename'         => $this->rename( $item, $payload, $taxonomy ),
			'merge_post'     => $this->merge_post( $item, $payload, $taxonomy ),
			'merge_finalize' => $this->delete_or_retain( $item, $payload, $taxonomy, true ),
			'delete'         => $this->delete_or_retain( $item, $payload, $taxonomy, false ),
			default          => $this->failure( ExecutionErrorCode::INVALID_OPERATION ),
		};
	}

	/**
	 * Applies a fixed rename.
	 *
	 * @param array<string, mixed> $item     Item row.
	 * @param array<string, mixed> $payload Fixed payload.
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @return string
	 * @throws ExecutionException When current data conflicts or WordPress fails.
	 */
	private function rename( array $item, array $payload, Taxonomy $taxonomy ): string {
		$term = $this->term( (int) ( $payload['term_id'] ?? 0 ), $taxonomy );
		$old  = is_array( $payload['before'] ?? null ) ? $payload['before'] : array();
		$new  = is_array( $payload['after'] ?? null ) ? $payload['after'] : array();
		if ( (string) ( $new['name'] ?? '' ) === (string) $term->name && (string) ( $new['slug'] ?? '' ) === (string) $term->slug ) {
			$this->record_rename_changes( $item, $old, $new, $term->term_id );
			return 'completed';
		}
		if ( (string) ( $old['name'] ?? '' ) !== (string) $term->name || (string) ( $old['slug'] ?? '' ) !== (string) $term->slug ) {
			$this->failure( ExecutionErrorCode::TARGET_CHANGED );
		}
		$args = array( 'name' => (string) $new['name'] );
		if ( (string) $old['slug'] !== (string) $new['slug'] ) {
			$args['slug'] = (string) $new['slug'];
		}
		$result = wp_update_term( $term->term_id, $taxonomy->value, $args );
		if ( $result instanceof WP_Error ) {
			$this->failure( ExecutionErrorCode::UPDATE_FAILED );
		}
		$this->record_rename_changes( $item, $old, $new, $term->term_id );
		return 'completed';
	}

	/**
	 * Moves one fixed published-post relationship.
	 *
	 * @param array<string, mixed> $item     Item row.
	 * @param array<string, mixed> $payload Fixed payload.
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @return string
	 * @throws ExecutionException When current data conflicts or WordPress fails.
	 */
	private function merge_post( array $item, array $payload, Taxonomy $taxonomy ): string {
		$post_id        = (int) ( $payload['post_id'] ?? 0 );
		$source_id      = (int) ( $payload['source_id'] ?? 0 );
		$destination_id = (int) ( $payload['destination_id'] ?? 0 );
		$post           = get_post( $post_id );
		$source         = $this->term( $source_id, $taxonomy );
		$destination    = $this->term( $destination_id, $taxonomy );
		if ( ! $this->matches_snapshot( $source, (array) ( $payload['source_snapshot'] ?? array() ) ) || ! $this->matches_snapshot( $destination, (array) ( $payload['destination_snapshot'] ?? array() ) ) ) {
			$this->failure( ExecutionErrorCode::TARGET_CHANGED );
		}
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			$this->failure( ExecutionErrorCode::TARGET_CHANGED );
		}
		$has_source      = has_term( $source_id, $taxonomy->value, $post_id );
		$has_destination = has_term( $destination_id, $taxonomy->value, $post_id );
		if ( ! $has_source && ! $has_destination ) {
			$this->failure( ExecutionErrorCode::TARGET_CHANGED );
		}
		if ( ! $has_destination ) {
			$result = wp_set_object_terms( $post_id, array( $destination_id ), $taxonomy->value, true );
			if ( $result instanceof WP_Error ) {
				$this->failure( ExecutionErrorCode::UPDATE_FAILED );
			}
			$this->record( $item, 'post:' . $post_id . ':destination', 'destination_added', array( 'assigned' => false ), array( 'assigned' => true ), $post_id );
		} elseif ( (bool) ( $payload['destination_present'] ?? false ) ) {
			$this->record( $item, 'post:' . $post_id . ':destination-existing', 'destination_existing', array( 'assigned' => true ), array( 'assigned' => true ), $post_id );
		}
		if ( $has_source ) {
			$result = wp_remove_object_terms( $post_id, array( $source_id ), $taxonomy->value );
			if ( $result instanceof WP_Error || false === $result ) {
				$this->failure( ExecutionErrorCode::UPDATE_FAILED );
			}
			$this->record( $item, 'post:' . $post_id . ':source:' . $source_id, 'source_removed', array( 'assigned' => true ), array( 'assigned' => false ), $post_id );
		}
		return 'completed';
	}

	/**
	 * Deletes a safe term or records why a merge source remains.
	 *
	 * @param array<string, mixed> $item     Item row.
	 * @param array<string, mixed> $payload Fixed payload.
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param bool                 $merge    Whether retention is allowed.
	 * @return string
	 * @throws ExecutionException When the term cannot safely be deleted.
	 */
	private function delete_or_retain( array $item, array $payload, Taxonomy $taxonomy, bool $merge ): string {
		$term     = $this->term( (int) ( $payload['term_id'] ?? 0 ), $taxonomy );
		$snapshot = is_array( $payload['snapshot'] ?? null ) ? $payload['snapshot'] : array();
		if ( ! $this->matches_snapshot( $term, $snapshot ) ) {
			if ( ! $merge ) {
				$this->failure( ExecutionErrorCode::TARGET_CHANGED );
			}
			$this->record( $item, 'term:retain', 'source_retained', null, array( 'reason' => 'term_changed' ), $term->term_id );
			return 'skipped';
		}
		$relationships = get_objects_in_term( $term->term_id, $taxonomy->value );
		if ( $relationships instanceof WP_Error ) {
			$this->failure( ExecutionErrorCode::UPDATE_FAILED );
		}
		$children = Taxonomy::CATEGORY === $taxonomy ? get_terms(
			array(
				'taxonomy'   => $taxonomy->value,
				'parent'     => $term->term_id,
				'fields'     => 'ids',
				'hide_empty' => false,
			)
		) : array();
		$reason   = '';
		if ( array() !== $relationships ) {
			$reason = 'relationships_remain';
		} elseif ( is_array( $children ) && array() !== $children ) {
			$reason = 'child_categories_remain';
		} elseif ( Taxonomy::CATEGORY === $taxonomy && (int) get_option( 'default_category' ) === $term->term_id ) {
			$reason = 'default_category';
		}
		if ( '' !== $reason ) {
			if ( ! $merge ) {
				$this->failure( ExecutionErrorCode::TARGET_CHANGED );
			}
			$this->record( $item, 'term:retain', 'source_retained', null, array( 'reason' => $reason ), $term->term_id );
			return 'skipped';
		}
		$result = wp_delete_term( $term->term_id, $taxonomy->value );
		if ( $result instanceof WP_Error || false === $result ) {
			$this->failure( ExecutionErrorCode::UPDATE_FAILED );
		}
		$snapshot['relationship_count'] = count( $relationships );
		$this->record(
			$item,
			'term:delete',
			$merge ? 'source_deleted' : 'term_deleted',
			$snapshot,
			array(
				'deleted_at' => current_time( 'mysql', true ),
				'result'     => 'deleted',
			),
			$term->term_id
		);
		return 'completed';
	}

	/**
	 * Records the exact rename fields that changed.
	 *
	 * @param array<string, mixed> $item    Item row.
	 * @param array<string, mixed> $before  Prior term snapshot.
	 * @param array<string, mixed> $after   Result term snapshot.
	 * @param int                  $term_id Affected term ID.
	 */
	private function record_rename_changes( array $item, array $before, array $after, int $term_id ): void {
		if ( (string) $before['name'] !== (string) $after['name'] ) {
			$this->record( $item, 'term:name', 'name_changed', array( 'name' => $before['name'] ), array( 'name' => $after['name'] ), $term_id );
		}
		if ( (string) $before['slug'] !== (string) $after['slug'] ) {
			$this->record( $item, 'term:slug', 'slug_changed', array( 'slug' => $before['slug'] ), array( 'slug' => $after['slug'] ), $term_id );
		}
	}

	/**
	 * Records a safe failure code when the journal itself remains available.
	 *
	 * @param array<string, mixed> $item Item row.
	 * @param string               $code Stable failure code.
	 */
	public function record_failure( array $item, string $code ): void {
		$this->record( $item, 'failure', 'item_failed', null, array( 'error_code' => $code ), null );
	}

	/**
	 * Requires a term in the expected taxonomy.
	 *
	 * @param int      $term_id  Term ID.
	 * @param Taxonomy $taxonomy Expected taxonomy.
	 * @return WP_Term
	 * @throws ExecutionException When the term is missing or mismatched.
	 */
	private function term( int $term_id, Taxonomy $taxonomy ): WP_Term {
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term || $taxonomy->value !== $term->taxonomy ) {
			$this->failure( ExecutionErrorCode::TARGET_CHANGED );
		}
		return $term;
	}

	/**
	 * Compares stable relevant term fields with the execution-start snapshot.
	 *
	 * @param WP_Term              $term     Current term.
	 * @param array<string, mixed> $snapshot Fixed start snapshot.
	 */
	private function matches_snapshot( WP_Term $term, array $snapshot ): bool {
		return (int) ( $snapshot['term_id'] ?? 0 ) === $term->term_id
			&& (int) ( $snapshot['term_taxonomy_id'] ?? 0 ) === (int) $term->term_taxonomy_id
			&& (string) ( $snapshot['taxonomy'] ?? '' ) === $term->taxonomy
			&& (string) ( $snapshot['name'] ?? '' ) === $term->name
			&& (string) ( $snapshot['slug'] ?? '' ) === $term->slug
			&& (int) ( $snapshot['parent'] ?? -1 ) === (int) $term->parent;
	}

	/**
	 * Records one actual change with a stable key.
	 *
	 * @param array<string, mixed>      $item      Item row.
	 * @param string                    $suffix    Per-item change suffix.
	 * @param string                    $type      Actual change type.
	 * @param array<string, mixed>|null $before    Prior state.
	 * @param array<string, mixed>|null $after     Result state.
	 * @param int|null                  $object_id Affected object ID.
	 */
	private function record( array $item, string $suffix, string $type, ?array $before, ?array $after, ?int $object_id ): void {
		$this->journal->record_once( (int) $item['operation_id'], (int) $item['id'], (string) $item['item_key'] . ':' . $suffix, $type, $before, $after, $object_id );
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
