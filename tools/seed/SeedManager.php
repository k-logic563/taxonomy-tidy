<?php
/**
 * Local-development seed data manager.
 *
 * This file is executed only by WP-CLI and is excluded from plugin packages.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Development;

use RuntimeException;
use WP_Error;
use WP_Post;
use WP_Term;

/**
 * Creates deterministic Phase 3 fixtures and removes only verified seed data.
 */
final class SeedManager {
	/**
	 * Registry option containing every seed-owned object ID.
	 *
	 * @var string
	 */
	public const REGISTRY_OPTION = 'term_steward_dev_seed_registry';

	/**
	 * Marker applied to every seed-owned post and term.
	 *
	 * @var string
	 */
	public const MARKER = 'term-steward-dev-seed-v1';

	/**
	 * Marker metadata key.
	 *
	 * @var string
	 */
	private const MARKER_META = '_term_steward_seed_marker';

	/**
	 * Stable fixture-key metadata key.
	 *
	 * @var string
	 */
	private const KEY_META = '_term_steward_seed_key';

	/**
	 * Creates or cleans one deterministic dataset.
	 *
	 * @param string $command          Supported command: demo, large, or clean.
	 * @param string $environment_type Current WordPress environment type.
	 * @return array{mode: string, posts: int, categories: int, tags: int}
	 * @throws RuntimeException When the environment, command, registry, or WordPress operation is unsafe.
	 */
	public function execute( string $command, string $environment_type ): array {
		if ( 'local' !== $environment_type ) {
			throw new RuntimeException( 'Seed commands are available only when WP_ENVIRONMENT_TYPE is local.' );
		}

		if ( 'clean' === $command ) {
			return $this->clean();
		}

		if ( ! in_array( $command, array( 'demo', 'large' ), true ) ) {
			throw new RuntimeException( 'Unknown seed command. Expected demo, large, or clean.' );
		}

		$registry = $this->load_or_create_registry( $command );
		$dataset  = 'demo' === $command ? $this->demo_dataset() : $this->large_dataset();

		foreach ( $dataset['categories'] as $key => $definition ) {
			$parent_id = 0;

			if ( isset( $definition['parent'] ) ) {
				$parent_id = (int) ( $registry['terms']['category'][ $definition['parent'] ] ?? 0 );

				if ( 0 === $parent_id ) {
					throw new RuntimeException( 'A seed category parent was not created before its child.' );
				}
			}

			$registry['terms']['category'][ $key ] = $this->ensure_term(
				$registry,
				$key,
				(string) $definition['name'],
				'category',
				$parent_id
			);
			$this->save_registry( $registry );
		}

		foreach ( $dataset['tags'] as $key => $definition ) {
			$registry['terms']['post_tag'][ $key ] = $this->ensure_term(
				$registry,
				$key,
				(string) $definition['name'],
				'post_tag',
				0
			);
			$this->save_registry( $registry );
		}

		foreach ( $dataset['posts'] as $key => $definition ) {
			$registry['posts'][ $key ] = $this->ensure_post( $registry, $key, $definition );
			$this->save_registry( $registry );
		}

		$this->apply_assignments( $registry, $dataset['assignments'] );
		$registry['complete'] = true;
		$this->save_registry( $registry );

		return $this->summary( $registry );
	}

	/**
	 * Deletes all registered seed objects after validating every surviving ID.
	 *
	 * @return array{mode: string, posts: int, categories: int, tags: int}
	 * @throws RuntimeException When ownership cannot be proven or deletion fails.
	 */
	private function clean(): array {
		$registry = get_option( self::REGISTRY_OPTION, null );

		if ( null === $registry || false === $registry ) {
			return array(
				'mode'       => 'clean',
				'posts'      => 0,
				'categories' => 0,
				'tags'       => 0,
			);
		}

		$registry = $this->validate_registry( $registry );
		$this->assert_clean_is_safe( $registry );
		$summary = $this->summary( $registry );

		foreach ( $registry['posts'] as $post_id ) {
			if ( null !== get_post( (int) $post_id ) && false === wp_delete_post( (int) $post_id, true ) ) {
				throw new RuntimeException( 'A registered seed post could not be deleted.' );
			}
		}

		$category_ids = array_map( 'intval', array_values( $registry['terms']['category'] ) );
		usort(
			$category_ids,
			static fn( int $left, int $right ): int => count( get_ancestors( $right, 'category', 'taxonomy' ) )
				<=> count( get_ancestors( $left, 'category', 'taxonomy' ) )
		);

		foreach ( $category_ids as $term_id ) {
			if ( $this->term_exists( $term_id, 'category' ) ) {
				$this->assert_not_error( wp_delete_term( $term_id, 'category' ), 'A seed category could not be deleted.' );
			}
		}

		foreach ( $registry['terms']['post_tag'] as $term_id ) {
			if ( $this->term_exists( (int) $term_id, 'post_tag' ) ) {
				$this->assert_not_error( wp_delete_term( (int) $term_id, 'post_tag' ), 'A seed tag could not be deleted.' );
			}
		}

		if ( ! delete_option( self::REGISTRY_OPTION ) ) {
			throw new RuntimeException( 'The seed registry could not be deleted.' );
		}

		$summary['mode'] = 'clean';

		return $summary;
	}

	/**
	 * Loads the current registry or initializes one before creating any objects.
	 *
	 * @param string $mode Requested dataset mode.
	 * @return array<string, mixed>
	 * @throws RuntimeException When an existing registry is corrupt or belongs to another dataset.
	 */
	private function load_or_create_registry( string $mode ): array {
		$stored = get_option( self::REGISTRY_OPTION, null );

		if ( null !== $stored && false !== $stored ) {
			$registry = $this->validate_registry( $stored );

			if ( $mode !== $registry['mode'] ) {
				throw new RuntimeException( 'Another seed dataset exists. Run make seed-clean before changing modes.' );
			}

			return $registry;
		}

		$registry = array(
			'marker'   => self::MARKER,
			'mode'     => $mode,
			'complete' => false,
			'posts'    => array(),
			'terms'    => array(
				'category' => array(),
				'post_tag' => array(),
			),
		);

		if ( ! add_option( self::REGISTRY_OPTION, $registry, '', false ) ) {
			throw new RuntimeException( 'The seed registry could not be initialized.' );
		}

		return $registry;
	}

	/**
	 * Validates the minimum registry shape and marker before any destructive use.
	 *
	 * @param mixed $registry Stored option value.
	 * @return array<string, mixed>
	 * @throws RuntimeException When the registry cannot prove seed ownership.
	 */
	private function validate_registry( mixed $registry ): array {
		if (
			! is_array( $registry )
			|| self::MARKER !== ( $registry['marker'] ?? null )
			|| ! in_array( $registry['mode'] ?? null, array( 'demo', 'large' ), true )
			|| ! is_array( $registry['posts'] ?? null )
			|| ! is_array( $registry['terms']['category'] ?? null )
			|| ! is_array( $registry['terms']['post_tag'] ?? null )
		) {
			throw new RuntimeException( 'The seed registry is invalid; no data was changed.' );
		}

		return $registry;
	}

	/**
	 * Creates or restores one deterministic seed term.
	 *
	 * @param array<string, mixed> $registry Current registry.
	 * @param string               $key      Stable fixture key.
	 * @param string               $name     Visible Japanese term name.
	 * @param string               $taxonomy Core taxonomy name.
	 * @param int                  $parent_id Parent category term ID.
	 * @throws RuntimeException When an existing term is not seed-owned or persistence fails.
	 */
	private function ensure_term( array $registry, string $key, string $name, string $taxonomy, int $parent_id ): int {
		$registered_id = (int) ( $registry['terms'][ $taxonomy ][ $key ] ?? 0 );
		$term          = 0 < $registered_id ? get_term( $registered_id, $taxonomy ) : null;

		if ( ! $term instanceof WP_Term ) {
			$slug = sprintf( 'tt-%s-%s-%s', $registry['mode'], 'category' === $taxonomy ? 'cat' : 'tag', $key );
			$term = get_term_by( 'slug', $slug, $taxonomy );

			if ( false === $term ) {
				$result = wp_insert_term(
					$name,
					$taxonomy,
					array(
						'description' => self::MARKER . ':' . $key,
						'slug'        => $slug,
						'parent'      => $parent_id,
					)
				);
				$this->assert_not_error( $result, 'A seed term could not be created.' );
				$term = get_term( (int) $result['term_id'], $taxonomy );
			}
		}

		if ( ! $term instanceof WP_Term ) {
			throw new RuntimeException( 'A seed term could not be loaded after creation.' );
		}

		$marker   = get_term_meta( $term->term_id, self::MARKER_META, true );
		$seed_key = get_term_meta( $term->term_id, self::KEY_META, true );

		if ( '' !== $marker && ( self::MARKER !== $marker || $key !== $seed_key ) ) {
			throw new RuntimeException( 'A seed term identifier conflicts with data not owned by this seed.' );
		}

		if ( '' === $marker ) {
			$expected_slug = sprintf( 'tt-%s-%s-%s', $registry['mode'], 'category' === $taxonomy ? 'cat' : 'tag', $key );

			if ( $expected_slug !== $term->slug || self::MARKER . ':' . $key !== $term->description ) {
				throw new RuntimeException( 'An existing unmarked term blocks a seed identifier.' );
			}

			update_term_meta( $term->term_id, self::MARKER_META, self::MARKER );
			update_term_meta( $term->term_id, self::KEY_META, $key );
		}

		$updated = wp_update_term(
			$term->term_id,
			$taxonomy,
			array(
				'description' => self::MARKER . ':' . $key,
				'name'        => $name,
				'parent'      => $parent_id,
			)
		);
		$this->assert_not_error( $updated, 'A seed term could not be normalized.' );

		return $term->term_id;
	}

	/**
	 * Creates or restores one deterministic seed post or page.
	 *
	 * @param array<string, mixed>  $registry   Current registry.
	 * @param string                $key        Stable fixture key.
	 * @param array<string, string> $definition Post definition.
	 * @throws RuntimeException When an existing post is not seed-owned or persistence fails.
	 */
	private function ensure_post( array $registry, string $key, array $definition ): int {
		$registered_id = (int) ( $registry['posts'][ $key ] ?? 0 );
		$post          = 0 < $registered_id ? get_post( $registered_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			$matches = get_posts(
				array(
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Seed data is intentionally located by its private ownership key.
					'meta_key'       => self::KEY_META,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The bounded local dataset uses an exact fixture-key lookup.
					'meta_value'     => $key,
					'post_status'    => 'any',
					'post_type'      => array( 'post', 'page' ),
					'posts_per_page' => 2,
				)
			);

			if ( 1 < count( $matches ) ) {
				throw new RuntimeException( 'More than one post uses a seed fixture key.' );
			}

			$post = 1 === count( $matches ) ? get_post( (int) $matches[0] ) : null;
		}

		if ( $post instanceof WP_Post ) {
			if (
				self::MARKER !== get_post_meta( $post->ID, self::MARKER_META, true )
				|| get_post_meta( $post->ID, self::KEY_META, true ) !== $key
			) {
				throw new RuntimeException( 'A registered seed post is not marked as seed-owned.' );
			}
			$post_id = $post->ID;
		} else {
			$post_id = wp_insert_post(
				array(
					'meta_input'   => array(
						self::MARKER_META => self::MARKER,
						self::KEY_META    => $key,
					),
					'post_content' => 'ローカル環境でTerm Stewardの表示を確認するための記事です。',
					'post_name'    => sprintf( 'tt-%s-%s', $registry['mode'], $key ),
					'post_status'  => $definition['status'],
					'post_title'   => $definition['title'],
					'post_type'    => $definition['type'],
					'post_date'    => $definition['date'],
				),
				true
			);
			$this->assert_not_error( $post_id, 'A seed post could not be created.' );
		}

		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_date'   => $definition['date'],
				'post_status' => $definition['status'],
				'post_title'  => $definition['title'],
				'post_type'   => $definition['type'],
			),
			true
		);
		$this->assert_not_error( $updated_id, 'A seed post could not be normalized.' );

		return (int) $post_id;
	}

	/**
	 * Applies deterministic category and tag relationships to seed posts.
	 *
	 * @param array<string, mixed>                                                 $registry    Current registry.
	 * @param array<string, array{category: list<string>, post_tag: list<string>}> $assignments Fixture keys by post.
	 * @throws RuntimeException When a fixture key is missing or assignment fails.
	 */
	private function apply_assignments( array $registry, array $assignments ): void {
		foreach ( $assignments as $post_key => $taxonomy_assignments ) {
			$post_id = (int) ( $registry['posts'][ $post_key ] ?? 0 );

			if ( 0 === $post_id ) {
				throw new RuntimeException( 'A relationship references a missing seed post.' );
			}

			foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
				$term_ids = array();

				foreach ( $taxonomy_assignments[ $taxonomy ] as $term_key ) {
					$term_id = (int) ( $registry['terms'][ $taxonomy ][ $term_key ] ?? 0 );

					if ( 0 === $term_id ) {
						throw new RuntimeException( 'A relationship references a missing seed term.' );
					}

					$term_ids[] = $term_id;
				}

				$this->assert_not_error(
					wp_set_object_terms( $post_id, $term_ids, $taxonomy, false ),
					'A seed relationship could not be saved.'
				);
			}
		}
	}

	/**
	 * Ensures every existing registry object is marked and isolated from user data.
	 *
	 * @param array<string, mixed> $registry Validated registry.
	 * @throws RuntimeException When ownership or relationship isolation cannot be proven.
	 */
	private function assert_clean_is_safe( array $registry ): void {
		$problems      = array();
		$seed_post_ids = array_map( 'intval', array_values( $registry['posts'] ) );

		foreach ( $registry['posts'] as $key => $post_id ) {
			$post = get_post( (int) $post_id );

			if ( null !== $post && (
				self::MARKER !== get_post_meta( (int) $post_id, self::MARKER_META, true )
				|| get_post_meta( (int) $post_id, self::KEY_META, true ) !== $key
			) ) {
				$problems[] = sprintf( 'Post %d is not verifiably seed-owned.', $post_id );
			}
		}

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			foreach ( $registry['terms'][ $taxonomy ] as $key => $term_id ) {
				$term = get_term( (int) $term_id, $taxonomy );

				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				if (
					self::MARKER !== get_term_meta( $term->term_id, self::MARKER_META, true )
					|| get_term_meta( $term->term_id, self::KEY_META, true ) !== $key
				) {
					$problems[] = sprintf( 'Term %d is not verifiably seed-owned.', $term_id );
					continue;
				}

				if ( 'category' === $taxonomy && (int) get_option( 'default_category' ) === $term->term_id ) {
					$problems[] = sprintf( 'Term %d is the default category and cannot be deleted.', $term_id );
				}

				$object_ids = get_objects_in_term( $term->term_id, $taxonomy );

				if ( is_wp_error( $object_ids ) ) {
					$problems[] = sprintf( 'Relationships for term %d could not be verified.', $term_id );
					continue;
				}

				foreach ( array_map( 'intval', $object_ids ) as $object_id ) {
					if ( ! in_array( $object_id, $seed_post_ids, true ) ) {
						$problems[] = sprintf( 'Term %d is attached to non-seed object %d.', $term_id, $object_id );
					}
				}
			}
		}

		if ( array() !== $problems ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI catches this internal exception; it is not HTML output.
			throw new RuntimeException( implode( ' ', $problems ) . ' No data was deleted.' );
		}
	}

	/**
	 * Persists a recovery checkpoint after each created object.
	 *
	 * @param array<string, mixed> $registry Registry to persist.
	 * @throws RuntimeException When the checkpoint cannot be saved.
	 */
	private function save_registry( array $registry ): void {
		if ( ! update_option( self::REGISTRY_OPTION, $registry, false ) ) {
			$stored = get_option( self::REGISTRY_OPTION, null );

			if ( $stored !== $registry ) {
				throw new RuntimeException( 'The seed registry checkpoint could not be saved.' );
			}
		}
	}

	/**
	 * Creates the fixed demo dataset definition.
	 *
	 * @return array<string, mixed>
	 */
	private function demo_dataset(): array {
		$categories = array(
			'development' => array( 'name' => '開発' ),
			'wordpress'   => array(
				'name'   => 'WordPress',
				'parent' => 'development',
			),
			'javascript'  => array(
				'name'   => 'JavaScript',
				'parent' => 'development',
			),
			'php'         => array(
				'name'   => 'PHP',
				'parent' => 'development',
			),
			'marketing'   => array( 'name' => 'マーケティング' ),
			'seo'         => array(
				'name'   => 'SEO',
				'parent' => 'marketing',
			),
			'sns'         => array(
				'name'   => 'SNS',
				'parent' => 'marketing',
			),
			'ec'          => array( 'name' => 'EC' ),
			'amazon'      => array(
				'name'   => 'Amazon',
				'parent' => 'ec',
			),
			'rakuten'     => array(
				'name'   => '楽天市場',
				'parent' => 'ec',
			),
			'yahoo'       => array(
				'name'   => 'Yahoo!ショッピング',
				'parent' => 'ec',
			),
			'design'      => array( 'name' => 'デザイン' ),
			'ui'          => array(
				'name'   => 'UI',
				'parent' => 'design',
			),
			'ux'          => array(
				'name'   => 'UX',
				'parent' => 'design',
			),
			'business'    => array( 'name' => 'ビジネス' ),
			'management'  => array( 'name' => '運営' ),
			'news'        => array( 'name' => 'ニュース' ),
			'technology'  => array( 'name' => 'テクノロジー' ),
			'security'    => array( 'name' => 'セキュリティ' ),
			'cloud'       => array( 'name' => 'クラウド' ),
			'database'    => array( 'name' => 'データベース' ),
			'mobile'      => array( 'name' => 'モバイル' ),
			'analytics'   => array( 'name' => 'アクセス解析' ),
			'beginner'    => array( 'name' => '初心者向け' ),
			'unused'      => array( 'name' => '完全未使用カテゴリー' ),
		);
		$tags       = array(
			'wordpress'       => array( 'name' => 'WordPress' ),
			'wordpress_lower' => array( 'name' => 'wordpress' ),
			'wp'              => array( 'name' => 'WP' ),
			'wordpress_ja'    => array( 'name' => 'ワードプレス' ),
			'javascript'      => array( 'name' => 'JavaScript' ),
			'javascript_alt'  => array( 'name' => 'Javascript' ),
			'js'              => array( 'name' => 'JS' ),
			'seo'             => array( 'name' => 'SEO' ),
			'seo_lower'       => array( 'name' => 'seo' ),
			'web'             => array( 'name' => 'Web制作' ),
			'web_upper'       => array( 'name' => 'WEB制作' ),
			'published_only'  => array( 'name' => '公開済み投稿で使用中' ),
			'published_draft' => array( 'name' => '公開済み投稿と下書きで使用中' ),
			'draft_only'      => array( 'name' => '下書きだけで使用中' ),
			'excluded_only'   => array( 'name' => '対象外オブジェクトだけで使用中' ),
			'unused'          => array( 'name' => '完全に未使用' ),
			'merge_source'    => array( 'name' => '統合元候補' ),
			'merge_target'    => array( 'name' => '統合先候補' ),
		);

		for ( $index = 19; $index <= 75; ++$index ) {
			$tags[ sprintf( 'topic_%02d', $index ) ] = array( 'name' => sprintf( '検証タグ%02d', $index ) );
		}

		$posts       = $this->post_definitions( 'demo', 90, 10, 5, 5, 5 );
		$assignments = $this->base_assignments(
			$posts,
			array_values( array_diff( array_keys( $categories ), array( 'unused' ) ) ),
			array_values( array_filter( array_keys( $tags ), static fn( string $key ): bool => str_starts_with( $key, 'topic_' ) ) ),
			2,
			4
		);

		$this->append_terms( $assignments, 'published_001', 'post_tag', array( 'wordpress', 'wordpress_lower', 'wp', 'wordpress_ja' ) );
		$this->append_terms( $assignments, 'published_002', 'post_tag', array( 'javascript', 'javascript_alt', 'js' ) );
		$this->append_terms( $assignments, 'published_003', 'post_tag', array( 'seo', 'seo_lower' ) );
		$this->append_terms( $assignments, 'published_004', 'post_tag', array( 'web', 'web_upper' ) );
		$this->append_terms( $assignments, 'published_005', 'post_tag', array( 'published_only' ) );
		$this->append_terms( $assignments, 'published_006', 'post_tag', array( 'published_draft' ) );
		$this->append_terms( $assignments, 'draft_001', 'post_tag', array( 'published_draft' ) );
		$this->append_terms( $assignments, 'draft_002', 'post_tag', array( 'draft_only' ) );
		$this->append_terms( $assignments, 'page_001', 'post_tag', array( 'excluded_only' ) );
		$this->append_terms( $assignments, 'private_001', 'post_tag', array( 'excluded_only' ) );
		$this->append_terms( $assignments, 'published_007', 'post_tag', array( 'merge_source', 'merge_target' ) );

		return array(
			'categories'  => $categories,
			'tags'        => $tags,
			'posts'       => $posts,
			'assignments' => $assignments,
		);
	}

	/**
	 * Creates the fixed large dataset definition.
	 *
	 * @return array<string, mixed>
	 */
	private function large_dataset(): array {
		$categories = array();
		$tags       = array();

		for ( $index = 1; $index <= 100; ++$index ) {
			$key                = sprintf( 'category_%03d', $index );
			$categories[ $key ] = array( 'name' => sprintf( '大規模カテゴリー %03d', $index ) );
		}

		for ( $index = 1; $index <= 1000; ++$index ) {
			$key          = sprintf( 'tag_%04d', $index );
			$tags[ $key ] = array( 'name' => sprintf( '大規模検証タグ %04d', $index ) );
		}

		$posts        = $this->post_definitions( 'large', 320, 50, 25, 25, 0 );
		$published    = array_filter( $posts, static fn( array $post ): bool => 'publish' === $post['status'] );
		$excluded     = array_filter( $posts, static fn( array $post ): bool => 'publish' !== $post['status'] );
		$assignments  = $this->base_assignments(
			$published,
			array_slice( array_keys( $categories ), 0, 80 ),
			array_slice( array_keys( $tags ), 0, 800 ),
			2,
			5
		);
		$assignments += $this->base_assignments(
			$excluded,
			array_slice( array_keys( $categories ), 80, 10 ),
			array_slice( array_keys( $tags ), 800, 100 ),
			1,
			3
		);

		return array(
			'categories'  => $categories,
			'tags'        => $tags,
			'posts'       => $posts,
			'assignments' => $assignments,
		);
	}

	/**
	 * Creates deterministic post definitions for one dataset.
	 *
	 * @param string $mode       Dataset mode used in visible titles.
	 * @param int    $published  Published standard-post count.
	 * @param int    $drafts     Draft standard-post count.
	 * @param int    $private_count Private standard-post count.
	 * @param int    $future     Scheduled standard-post count.
	 * @param int    $pages      Published page count.
	 * @return array<string, array<string, string>>
	 */
	private function post_definitions(
		string $mode,
		int $published,
		int $drafts,
		int $private_count,
		int $future,
		int $pages
	): array {
		$definitions = array();
		$labels      = array(
			'published' => array(
				'status' => 'publish',
				'type'   => 'post',
				'label'  => '公開記事',
			),
			'draft'     => array(
				'status' => 'draft',
				'type'   => 'post',
				'label'  => '下書き記事',
			),
			'private'   => array(
				'status' => 'private',
				'type'   => 'post',
				'label'  => '非公開記事',
			),
			'future'    => array(
				'status' => 'future',
				'type'   => 'post',
				'label'  => '予約記事',
			),
			'page'      => array(
				'status' => 'publish',
				'type'   => 'page',
				'label'  => '固定ページ',
			),
		);
		$counts      = array(
			'published' => $published,
			'draft'     => $drafts,
			'private'   => $private_count,
			'future'    => $future,
			'page'      => $pages,
		);
		$topics      = array( 'サイト運営', '開発メモ', '集客のヒント', '制作事例', '技術解説', '更新情報' );

		foreach ( $counts as $kind => $count ) {
			for ( $index = 1; $index <= $count; ++$index ) {
				$key                 = sprintf( '%s_%03d', $kind, $index );
				$definitions[ $key ] = array(
					'date'   => 'future' === $kind ? sprintf( '2099-01-%02d 09:00:00', ( ( $index - 1 ) % 28 ) + 1 ) : '2026-01-01 09:00:00',
					'status' => $labels[ $kind ]['status'],
					'title'  => sprintf( '%s：%s %03d', $labels[ $kind ]['label'], $topics[ ( $index - 1 ) % count( $topics ) ], $index ),
					'type'   => $labels[ $kind ]['type'],
					'mode'   => $mode,
				);
			}
		}

		return $definitions;
	}

	/**
	 * Builds deterministic multi-term assignments without global random state.
	 *
	 * @param array<string, array<string, string>> $posts          Post definitions.
	 * @param array                                $category_keys  Available category keys.
	 * @param array                                $tag_keys       Available tag keys.
	 * @param int                                  $category_count Categories per object.
	 * @param int                                  $tag_count      Tags per object.
	 * @return array<string, array{category: list<string>, post_tag: list<string>}>
	 */
	private function base_assignments(
		array $posts,
		array $category_keys,
		array $tag_keys,
		int $category_count,
		int $tag_count
	): array {
		$assignments = array();
		$index       = 0;

		foreach ( array_keys( $posts ) as $post_key ) {
			$assignments[ $post_key ] = array(
				'category' => $this->select_keys( $category_keys, $index, $category_count, 7 ),
				'post_tag' => $this->select_keys( $tag_keys, $index, $tag_count, 13 ),
			);
			++$index;
		}

		return $assignments;
	}

	/**
	 * Selects unique deterministic keys for one object.
	 *
	 * @param array $keys  Candidate fixture keys.
	 * @param int   $index Object sequence number.
	 * @param int   $count Number of keys to select.
	 * @param int   $salt  Dataset-independent distribution salt.
	 * @return list<string>
	 */
	private function select_keys( array $keys, int $index, int $count, int $salt ): array {
		$selected = array();
		$total    = count( $keys );

		for ( $position = 0; $position < $count; ++$position ) {
			$selected[] = $keys[ ( ( $index * 17 ) + ( $position * 31 ) + $salt ) % $total ];
		}

		return array_values( array_unique( $selected ) );
	}

	/**
	 * Adds fixed relationship patterns to a generated assignment.
	 *
	 * @param array<string, array{category: list<string>, post_tag: list<string>}> $assignments Assignments to update.
	 * @param string                                                               $post_key    Target post fixture key.
	 * @param string                                                               $taxonomy    Target taxonomy.
	 * @param array                                                                $term_keys   Term fixture keys to append.
	 */
	private function append_terms( array &$assignments, string $post_key, string $taxonomy, array $term_keys ): void {
		$assignments[ $post_key ][ $taxonomy ] = array_values(
			array_unique( array_merge( $assignments[ $post_key ][ $taxonomy ], $term_keys ) )
		);
	}

	/**
	 * Returns object counts from a registry.
	 *
	 * @param array<string, mixed> $registry Valid registry.
	 * @return array{mode: string, posts: int, categories: int, tags: int}
	 */
	private function summary( array $registry ): array {
		return array(
			'mode'       => (string) $registry['mode'],
			'posts'      => count( $registry['posts'] ),
			'categories' => count( $registry['terms']['category'] ),
			'tags'       => count( $registry['terms']['post_tag'] ),
		);
	}

	/**
	 * Returns whether a taxonomy term still exists.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	private function term_exists( int $term_id, string $taxonomy ): bool {
		return get_term( $term_id, $taxonomy ) instanceof WP_Term;
	}

	/**
	 * Converts WordPress errors and false results into a stopping exception.
	 *
	 * @param mixed  $result  WordPress operation result.
	 * @param string $message Safe error summary.
	 * @throws RuntimeException When WordPress reports an error or false result.
	 */
	private function assert_not_error( mixed $result, string $message ): void {
		if ( $result instanceof WP_Error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI catches this internal exception; it is not HTML output.
			throw new RuntimeException( $message . ' ' . $result->get_error_message() );
		}

		if ( false === $result || null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI catches this internal exception; it is not HTML output.
			throw new RuntimeException( $message );
		}
	}
}
