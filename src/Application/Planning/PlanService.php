<?php
/**
 * Read-only operation planning and preview generation.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Planning;

use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\Json;
use TaxonomyTidy\Infrastructure\Persistence\PersistenceException;
use WP_Post;
use WP_Term;

/**
 * Validates normalized Phase 4 plans without changing WordPress content.
 */
final class PlanService {
	/**
	 * Validates and normalizes a complete operation plan.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param list<array<string, mixed>> $items    Untrusted plan items.
	 * @return list<array<string, mixed>>
	 * @throws PlanValidationException When any item or plan conflict is invalid.
	 */
	public function normalize( Taxonomy $taxonomy, array $items ): array {
		$normalized = array();
		$errors     = array();

		foreach ( $items as $item ) {
			try {
				$normalized[] = $this->normalize_item( $taxonomy, $item );
			} catch ( PlanValidationException $exception ) {
				$errors = array_merge( $errors, $exception->codes() );
			}
		}

		$source_ids      = array();
		$destination_ids = array();
		foreach ( $normalized as $item ) {
			foreach ( $item['sources'] as $source ) {
				$id = (int) $source['term_id'];
				if ( isset( $source_ids[ $id ] ) ) {
					$errors[] = PlanErrorCode::PLAN_CONFLICT;
				}
				$source_ids[ $id ] = true;
			}
			if ( is_array( $item['destination'] ) ) {
				$destination_ids[ (int) $item['destination']['term_id'] ] = true;
			}
		}

		if ( array_intersect_key( $source_ids, $destination_ids ) ) {
			$errors[] = PlanErrorCode::PLAN_CONFLICT;
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain validation codes are not HTML output.
			throw new PlanValidationException( $errors );
		}

		usort(
			$normalized,
			static fn( array $left, array $right ): int => strcmp( self::item_key( $left ), self::item_key( $right ) )
		);

		return $normalized;
	}

	/**
	 * Generates a truthful preview and fixed execution targets.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param list<array<string, mixed>> $items    Plan items.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When current WordPress state makes the plan invalid.
	 * @throws PersistenceException When normalized preview data cannot be encoded.
	 */
	public function preview( Taxonomy $taxonomy, array $items ): array {
		$plan          = $this->normalize( $taxonomy, $items );
		$preview_items = array();
		$all_targets   = array();
		$warning_count = 0;
		$delete_count  = 0;
		$retain_count  = 0;

		foreach ( $plan as $item ) {
			$preview         = $this->preview_item( $taxonomy, $item );
			$preview_items[] = $preview;
			$all_targets     = array_merge( $all_targets, $preview['target_post_ids'] );
			$warning_count  += count( $preview['warnings'] );
			$delete_count   += count( array_filter( $preview['sources'], static fn( array $source ): bool => $source['delete_source'] ) );
			$retain_count   += count( array_filter( $preview['sources'], static fn( array $source ): bool => ! $source['delete_source'] && Action::MERGE->value === $item['action'] ) );
		}

		$all_targets = array_values( array_unique( array_map( 'intval', $all_targets ) ) );
		sort( $all_targets, SORT_NUMERIC );

		return array(
			'plan'              => $plan,
			'plan_hash'         => $this->plan_hash( $plan ),
			'state_fingerprint' => $this->state_fingerprint( $taxonomy, $plan ),
			'target_post_ids'   => $all_targets,
			'items'             => $preview_items,
			'summary'           => array(
				'operation_count'   => count( $preview_items ),
				'target_post_count' => count( $all_targets ),
				'delete_term_count' => $delete_count,
				'retain_term_count' => $retain_count,
				'warning_count'     => $warning_count,
				'error_count'       => 0,
			),
			'created_at'        => current_time( 'mysql', true ),
		);
	}

	/**
	 * Returns a stable hash for an already normalized plan.
	 *
	 * @param list<array<string, mixed>> $plan Normalized plan.
	 * @throws PersistenceException When normalized plan data cannot be encoded.
	 */
	public function plan_hash( array $plan ): string {
		return hash( 'sha256', Json::encode( $plan ) );
	}

	/**
	 * Returns a hash of only relevant current taxonomy state.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param list<array<string, mixed>> $plan     Normalized plan.
	 * @throws PlanValidationException When a referenced term no longer exists.
	 * @throws PersistenceException When relevant state cannot be encoded.
	 */
	public function state_fingerprint( Taxonomy $taxonomy, array $plan ): string {
		$term_ids = array();
		foreach ( $plan as $item ) {
			foreach ( $item['sources'] as $source ) {
				$term_ids[] = (int) $source['term_id'];
			}
			if ( is_array( $item['destination'] ) ) {
				$term_ids[] = (int) $item['destination']['term_id'];
			}
		}

		$state    = array(
			'default_category' => Taxonomy::CATEGORY === $taxonomy ? (int) get_option( 'default_category' ) : null,
			'terms'            => array(),
		);
		$term_ids = array_values( array_unique( $term_ids ) );
		sort( $term_ids, SORT_NUMERIC );

		foreach ( $term_ids as $term_id ) {
			$term             = $this->require_term( $term_id, $taxonomy );
			$relationships    = $this->relationship_ids( $term );
			$state['terms'][] = array(
				'term_id'          => $term->term_id,
				'term_taxonomy_id' => (int) $term->term_taxonomy_id,
				'taxonomy'         => $term->taxonomy,
				'name'             => $term->name,
				'slug'             => $term->slug,
				'parent'           => (int) $term->parent,
				'relationships'    => $relationships,
				'published_posts'  => $this->published_posts( $relationships ),
			);
		}

		return hash( 'sha256', Json::encode( $state ) );
	}

	/**
	 * Reports whether a stored preview still matches current related state.
	 *
	 * @param Taxonomy                   $taxonomy            Current taxonomy.
	 * @param list<array<string, mixed>> $plan                Normalized plan.
	 * @param string                     $stored_fingerprint  Stored preview fingerprint.
	 * @throws PersistenceException When relevant state cannot be encoded.
	 */
	public function is_current( Taxonomy $taxonomy, array $plan, string $stored_fingerprint ): bool {
		try {
			return hash_equals( $stored_fingerprint, $this->state_fingerprint( $taxonomy, $plan ) );
		} catch ( PlanValidationException ) {
			return false;
		}
	}

	/**
	 * Normalizes one item and validates action-specific rules.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Untrusted item.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When item validation fails.
	 */
	private function normalize_item( Taxonomy $taxonomy, array $item ): array {
		$action = Action::tryFrom( is_string( $item['action'] ?? null ) ? $item['action'] : '' );
		$errors = null === $action ? array( PlanErrorCode::OPERATION_REQUIRED ) : array();

		if ( is_array( $item['sources'] ?? null ) ) {
			$item['source_ids']    = array_column( $item['sources'], 'term_id' );
			$item['source_tt_ids'] = array_column( $item['sources'], 'term_taxonomy_id', 'term_id' );
		}
		if ( is_array( $item['destination'] ?? null ) ) {
			$item['destination_id']    = $item['destination']['term_id'] ?? 0;
			$item['destination_tt_id'] = $item['destination']['term_taxonomy_id'] ?? 0;
		}
		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $item['source_ids'] ?? array() ) ) ) ) );
		if ( array() === $source_ids ) {
			$errors[] = PlanErrorCode::SELECTION_REQUIRED;
		} elseif ( count( $source_ids ) !== count( (array) ( $item['source_ids'] ?? array() ) ) ) {
			$errors[] = PlanErrorCode::SELECTION_INVALID;
		}
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain validation codes are not HTML output.
			throw new PlanValidationException( $errors );
		}
		sort( $source_ids, SORT_NUMERIC );

		$sources       = array();
		$source_tt_ids = (array) ( $item['source_tt_ids'] ?? array() );
		foreach ( $source_ids as $source_id ) {
			try {
				$term = $this->require_term( $source_id, $taxonomy );
			} catch ( PlanValidationException $exception ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
				throw new PlanValidationException( array( $exception->codes()[0] ?? PlanErrorCode::SELECTION_INVALID ) );
			}
			$client_tt = absint( $source_tt_ids[ $source_id ] ?? 0 );
			if ( $client_tt !== (int) $term->term_taxonomy_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
				throw new PlanValidationException( array( PlanErrorCode::TAXONOMY_MISMATCH ) );
			}
			$sources[] = array(
				'term_id'          => $term->term_id,
				'term_taxonomy_id' => (int) $term->term_taxonomy_id,
			);
		}

		$normalized = array(
			'action'      => $action->value,
			'taxonomy'    => $taxonomy->value,
			'sources'     => $sources,
			'destination' => null,
			'new_name'    => null,
			'new_slug'    => null,
		);

		if ( Action::RENAME === $action ) {
			return $this->normalize_rename( $taxonomy, $normalized, $item );
		}
		if ( Action::MERGE === $action ) {
			return $this->normalize_merge( $taxonomy, $normalized, $item );
		}

		return $this->normalize_delete( $taxonomy, $normalized );
	}

	/**
	 * Normalizes and validates a rename item.
	 *
	 * @param Taxonomy             $taxonomy   Current taxonomy.
	 * @param array<string, mixed> $normalized Normalized base item.
	 * @param array<string, mixed> $item       Untrusted item.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When rename input is invalid or conflicting.
	 */
	private function normalize_rename( Taxonomy $taxonomy, array $normalized, array $item ): array {
		if ( 1 !== count( $normalized['sources'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::SINGLE_TERM_REQUIRED ) );
		}
		$term     = $this->require_term( (int) $normalized['sources'][0]['term_id'], $taxonomy );
		$raw_name = is_string( $item['new_name'] ?? null ) ? trim( $item['new_name'] ) : '';
		$new_name = sanitize_text_field( $raw_name );
		$errors   = array();
		if ( '' === $new_name ) {
			$errors[] = PlanErrorCode::NEW_NAME_REQUIRED;
		} elseif ( $raw_name !== $new_name ) {
			$errors[] = PlanErrorCode::INVALID_NAME;
		} elseif ( $this->name_conflicts( $new_name, $taxonomy, $term->term_id ) ) {
			$errors[] = PlanErrorCode::NAME_CONFLICT;
		}

		$raw_slug = is_string( $item['new_slug'] ?? null ) ? trim( $item['new_slug'] ) : '';
		$new_slug = null;
		if ( '' !== $raw_slug && $term->slug !== $raw_slug ) {
			$sanitized_slug = sanitize_title( $raw_slug );
			if ( '' === $sanitized_slug || $raw_slug !== $sanitized_slug ) {
				$errors[] = PlanErrorCode::INVALID_SLUG;
			} else {
				$new_slug = $sanitized_slug;
				$existing = get_term_by( 'slug', $sanitized_slug, $taxonomy->value );
				if ( $existing instanceof WP_Term && $existing->term_id !== $term->term_id ) {
					$errors[] = PlanErrorCode::SLUG_CONFLICT;
				}
				if ( wp_unique_term_slug( $sanitized_slug, $term ) !== $sanitized_slug ) {
					$errors[] = PlanErrorCode::INVALID_SLUG;
				}
			}
		}

		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain validation codes are not HTML output.
			throw new PlanValidationException( $errors );
		}
		if ( $term->name === $new_name && null === $new_slug ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::NAME_UNCHANGED ) );
		}

		$normalized['new_name'] = $new_name;
		$normalized['new_slug'] = $new_slug;
		return $normalized;
	}

	/**
	 * Normalizes and validates a merge item.
	 *
	 * @param Taxonomy             $taxonomy   Current taxonomy.
	 * @param array<string, mixed> $normalized Normalized base item.
	 * @param array<string, mixed> $item       Untrusted item.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When the destination or hierarchy is invalid.
	 */
	private function normalize_merge( Taxonomy $taxonomy, array $normalized, array $item ): array {
		$destination_id = absint( $item['destination_id'] ?? 0 );
		if ( 0 === $destination_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::DESTINATION_REQUIRED ) );
		}
		$destination = get_term( $destination_id );
		if ( ! $destination instanceof WP_Term ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::DESTINATION_REQUIRED ) );
		}
		if ( $taxonomy->value !== $destination->taxonomy ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::TAXONOMY_MISMATCH ) );
		}
		if ( absint( $item['destination_tt_id'] ?? 0 ) !== (int) $destination->term_taxonomy_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::TAXONOMY_MISMATCH ) );
		}
		$source_ids = array_column( $normalized['sources'], 'term_id' );
		if ( in_array( $destination_id, $source_ids, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::SAME_SOURCE_DESTINATION ) );
		}
		if ( Taxonomy::CATEGORY === $taxonomy ) {
			$ancestors = array_map( 'intval', get_ancestors( $destination_id, $taxonomy->value, 'taxonomy' ) );
			if ( count( $ancestors ) !== count( array_unique( $ancestors ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
				throw new PlanValidationException( array( PlanErrorCode::CIRCULAR_HIERARCHY ) );
			}
			if ( array_intersect( $source_ids, $ancestors ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
				throw new PlanValidationException( array( PlanErrorCode::DESCENDANT_DESTINATION ) );
			}
		}
		$normalized['destination'] = array(
			'term_id'          => $destination->term_id,
			'term_taxonomy_id' => (int) $destination->term_taxonomy_id,
		);
		return $normalized;
	}

	/**
	 * Normalizes and validates a delete item.
	 *
	 * @param Taxonomy             $taxonomy   Current taxonomy.
	 * @param array<string, mixed> $normalized Normalized base item.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When deletion is unsafe.
	 */
	private function normalize_delete( Taxonomy $taxonomy, array $normalized ): array {
		$errors = array();
		foreach ( $normalized['sources'] as $source ) {
			$term = $this->require_term( (int) $source['term_id'], $taxonomy );
			if ( array() !== $this->relationship_ids( $term ) ) {
				$errors[] = PlanErrorCode::TERM_IN_USE;
			}
			if ( Taxonomy::CATEGORY === $taxonomy && (int) get_option( 'default_category' ) === $term->term_id ) {
				$errors[] = PlanErrorCode::DEFAULT_CATEGORY;
			}
		}
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain validation codes are not HTML output.
			throw new PlanValidationException( $errors );
		}
		return $normalized;
	}

	/**
	 * Builds one read-only preview item from current state.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Normalized item.
	 * @return array<string, mixed>
	 * @throws PlanValidationException When a referenced term or relationship cannot be read.
	 */
	private function preview_item( Taxonomy $taxonomy, array $item ): array {
		$sources        = array();
		$target_posts   = array();
		$excluded_ids   = array();
		$warnings       = array();
		$destination_id = is_array( $item['destination'] ) ? (int) $item['destination']['term_id'] : null;

		foreach ( $item['sources'] as $source_data ) {
			$term          = $this->require_term( (int) $source_data['term_id'], $taxonomy );
			$relationships = $this->relationship_ids( $term );
			$published     = $this->published_posts( $relationships );
			$published_ids = array_column( $published, 'id' );
			$excluded      = array_values( array_diff( $relationships, $published_ids ) );
			$children      = Taxonomy::CATEGORY === $taxonomy
				? get_terms(
					array(
						'taxonomy'   => $taxonomy->value,
						'parent'     => $term->term_id,
						'fields'     => 'ids',
						'hide_empty' => false,
					)
				)
				: array();
			$children      = is_array( $children ) ? array_map( 'intval', $children ) : array();
			$delete_source = Action::DELETE->value === $item['action']
				|| ( Action::MERGE->value === $item['action'] && array() === $excluded && array() === $children );
			$reasons       = array();
			if ( Action::MERGE->value === $item['action'] && array() !== $excluded ) {
				$reasons[]  = 'used_by_excluded_objects';
				$warnings[] = 'used_by_excluded_objects';
			}
			if ( Action::MERGE->value === $item['action'] && array() !== $children ) {
				$reasons[]  = 'has_child_categories';
				$warnings[] = 'has_child_categories';
			}
			if ( Action::DELETE->value === $item['action'] ) {
				$reasons[] = 'globally_unused';
			} elseif ( Action::MERGE->value === $item['action'] && $delete_source ) {
				$reasons[] = 'safe_after_published_reassignment';
			} elseif ( Action::RENAME->value === $item['action'] ) {
				$reasons[] = 'rename_preserves_term';
			}
			$sources[]    = array(
				'term_id'          => $term->term_id,
				'term_taxonomy_id' => (int) $term->term_taxonomy_id,
				'name'             => $term->name,
				'slug'             => $term->slug,
				'published_count'  => count( $published ),
				'excluded_count'   => count( $excluded ),
				'child_count'      => count( $children ),
				'delete_source'    => $delete_source,
				'reasons'          => $reasons,
				'target_post_ids'  => $published_ids,
			);
			$target_posts = array_merge( $target_posts, $published );
			$excluded_ids = array_merge( $excluded_ids, $excluded );
		}

		$target_posts = $this->unique_posts( $target_posts );
		$target_ids   = Action::MERGE->value === $item['action'] ? array_column( $target_posts, 'id' ) : array();
		return array(
			'action'              => $item['action'],
			'sources'             => $sources,
			'destination'         => null === $destination_id ? null : $this->term_label( $this->require_term( $destination_id, $taxonomy ) ),
			'new_name'            => $item['new_name'],
			'new_slug'            => $item['new_slug'],
			'target_post_ids'     => $target_ids,
			'affected_posts'      => $target_posts,
			'excluded_object_ids' => array_values( array_unique( array_map( 'intval', $excluded_ids ) ) ),
			'warnings'            => array_values( array_unique( $warnings ) ),
			'errors'              => array(),
		);
	}

	/**
	 * Returns a term only when it belongs to the expected taxonomy.
	 *
	 * @param int      $term_id  Term ID.
	 * @param Taxonomy $taxonomy Expected taxonomy.
	 * @return WP_Term
	 * @throws PlanValidationException When the term is missing or mismatched.
	 */
	private function require_term( int $term_id, Taxonomy $taxonomy ): WP_Term {
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::SELECTION_INVALID ) );
		}
		if ( $taxonomy->value !== $term->taxonomy ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::TAXONOMY_MISMATCH ) );
		}
		return $term;
	}

	/**
	 * Returns every object relationship for a term.
	 *
	 * @param WP_Term $term Term to inspect.
	 * @return list<int>
	 * @throws PlanValidationException When WordPress cannot read relationships.
	 */
	private function relationship_ids( WP_Term $term ): array {
		$ids = get_objects_in_term( $term->term_id, $term->taxonomy );
		if ( is_wp_error( $ids ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation codes are not HTML output.
			throw new PlanValidationException( array( PlanErrorCode::UNKNOWN_ERROR ) );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Filters relationships to published standard posts.
	 *
	 * @param array $object_ids Related object IDs.
	 * @return list<array{id: int, title: string}>
	 */
	private function published_posts( array $object_ids ): array {
		$posts = array();
		foreach ( $object_ids as $object_id ) {
			$post = get_post( $object_id );
			if ( $post instanceof WP_Post && 'post' === $post->post_type && 'publish' === $post->post_status ) {
				$posts[] = array(
					'id'    => $post->ID,
					'title' => get_the_title( $post ),
				);
			}
		}
		return $posts;
	}

	/**
	 * Deduplicates and orders preview posts.
	 *
	 * @param array $posts Post summaries.
	 * @return list<array{id: int, title: string}>
	 */
	private function unique_posts( array $posts ): array {
		$unique = array();
		foreach ( $posts as $post ) {
			$unique[ $post['id'] ] = $post;
		}
		ksort( $unique, SORT_NUMERIC );
		return array_values( $unique );
	}

	/**
	 * Detects an exact name conflict in one taxonomy.
	 *
	 * @param string   $name      Proposed name.
	 * @param Taxonomy $taxonomy  Current taxonomy.
	 * @param int      $source_id Source term ID to exclude.
	 * @return bool
	 */
	private function name_conflicts( string $name, Taxonomy $taxonomy, int $source_id ): bool {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy->value,
				'hide_empty' => false,
				'name'       => $name,
			)
		);
		if ( ! is_array( $terms ) ) {
			return true;
		}
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && $source_id !== $term->term_id && 0 === strcasecmp( $term->name, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns raw term fields for later escaped rendering.
	 *
	 * @param WP_Term $term Term to summarize.
	 * @return array<string, mixed>
	 */
	private function term_label( WP_Term $term ): array {
		return array(
			'term_id'          => $term->term_id,
			'term_taxonomy_id' => (int) $term->term_taxonomy_id,
			'name'             => $term->name,
			'slug'             => $term->slug,
		);
	}

	/**
	 * Returns a stable sorting key for a normalized item.
	 *
	 * @param array<string, mixed> $item Normalized item.
	 * @return string
	 */
	private static function item_key( array $item ): string {
		return $item['action'] . ':' . implode( ',', array_column( $item['sources'], 'term_id' ) );
	}
}
