<?php
/**
 * Taxonomy inventory query integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Taxonomy\TermInventoryQuery;
use WP_UnitTestCase;

/**
 * Verifies read-only taxonomy inventory counts, filters, and scale boundaries.
 */
final class TermInventoryQueryTest extends WP_UnitTestCase {
	/**
	 * Read-only query under test.
	 *
	 * @var TermInventoryQuery
	 */
	private TermInventoryQuery $query;

	/**
	 * Registers the test post type and creates the query service.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		register_post_type( 'inventory_book', array( 'public' => false ) );
		$this->query = new TermInventoryQuery( $wpdb );
	}

	/**
	 * Removes the temporary post type.
	 */
	public function tear_down(): void {
		unregister_post_type( 'inventory_book' );
		parent::tear_down();
	}

	/**
	 * Published counts exclude non-published standard posts and other post types.
	 */
	public function test_counts_published_posts_and_all_relationships_separately(): void {
		global $wpdb;

		$mixed_id     = self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::CATEGORY->value,
				'name'     => 'Inventory Mixed Usage',
				'slug'     => 'inventory-mixed-usage',
			)
		);
		$excluded_id  = self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::CATEGORY->value,
				'name'     => 'Inventory Excluded Usage',
				'slug'     => 'inventory-excluded-usage',
			)
		);
		$unused_id    = self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::CATEGORY->value,
				'name'     => 'Inventory Global Unused',
				'slug'     => 'inventory-global-unused',
			)
		);
		$published_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft_id     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$private_id   = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$future_id    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Avoids scheduling cron while creating an isolated future-post fixture.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_status' => 'future' ),
			array( 'ID' => $future_id )
		);
		$page_id          = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$custom_post_id   = self::factory()->post->create(
			array(
				'post_type'   => 'inventory_book',
				'post_status' => 'publish',
			)
		);
		$excluded_post_id = self::factory()->post->create( array( 'post_status' => 'pending' ) );

		foreach ( array( $published_id, $draft_id, $private_id, $future_id, $page_id, $custom_post_id ) as $post_id ) {
			$this->assign_term( $post_id, $mixed_id, Taxonomy::CATEGORY );
		}
		$this->assign_term( $excluded_post_id, $excluded_id, Taxonomy::CATEGORY );

		$mixed    = $this->find_one( Taxonomy::CATEGORY, 'inventory-mixed-usage' );
		$excluded = $this->find_one( Taxonomy::CATEGORY, 'inventory-excluded-usage' );
		$unused   = $this->find_one( Taxonomy::CATEGORY, 'inventory-global-unused' );

		$this->assertSame( $mixed_id, $mixed['term_id'] );
		$this->assertSame( 1, $mixed['published_post_count'] );
		$this->assertSame( 6, $mixed['total_relationship_count'] );
		$this->assertSame( 'published', $mixed['usage'] );
		$this->assertSame( 0, $excluded['published_post_count'] );
		$this->assertSame( 1, $excluded['total_relationship_count'] );
		$this->assertSame( 'excluded_only', $excluded['usage'] );
		$this->assertSame( $unused_id, $unused['term_id'] );
		$this->assertSame( 0, $unused['total_relationship_count'] );
		$this->assertSame( 'globally_unused', $unused['usage'] );
	}

	/**
	 * Category and tag searches remain separate and category parents are shown.
	 */
	public function test_searches_name_and_slug_in_separate_taxonomy_views(): void {
		$parent_id = self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::CATEGORY->value,
				'name'     => 'Inventory Parent',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::CATEGORY->value,
				'name'     => 'Inventory Child Name',
				'slug'     => 'category-special-slug',
				'parent'   => $parent_id,
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::POST_TAG->value,
				'name'     => 'Inventory Tag Name',
				'slug'     => 'tag-special-slug',
			)
		);

		$category_by_slug = $this->query->find( Taxonomy::CATEGORY, 'category-special' );
		$category_by_name = $this->query->find( Taxonomy::CATEGORY, 'Child Name' );
		$tag_by_slug      = $this->query->find( Taxonomy::POST_TAG, 'tag-special' );

		$this->assertSame( 1, $category_by_slug['total'] );
		$this->assertSame( 'category', $category_by_slug['items'][0]['taxonomy'] );
		$this->assertSame( 'Inventory Parent', $category_by_slug['items'][0]['parent_name'] );
		$this->assertSame( 1, $category_by_name['total'] );
		$this->assertSame( 1, $tag_by_slug['total'] );
		$this->assertSame( 'post_tag', $tag_by_slug['items'][0]['taxonomy'] );
		$this->assertNull( $tag_by_slug['items'][0]['parent_name'] );
	}

	/**
	 * Sorting, pagination, and global-unused filtering compose predictably.
	 */
	public function test_sorts_pages_and_filters_globally_unused_terms(): void {
		$alpha_id   = $this->create_category( 'Inventory Sort Alpha' );
		$bravo_id   = $this->create_category( 'Inventory Sort Bravo' );
		$charlie_id = $this->create_category( 'Inventory Sort Charlie' );

		$this->assign_term( self::factory()->post->create( array( 'post_status' => 'publish' ) ), $bravo_id, Taxonomy::CATEGORY );
		$this->assign_term( self::factory()->post->create( array( 'post_status' => 'publish' ) ), $charlie_id, Taxonomy::CATEGORY );
		$this->assign_term( self::factory()->post->create( array( 'post_status' => 'publish' ) ), $charlie_id, Taxonomy::CATEGORY );

		$first_page  = $this->query->find( Taxonomy::CATEGORY, 'Inventory Sort', 1, 2, 'published_count', 'desc' );
		$second_page = $this->query->find( Taxonomy::CATEGORY, 'Inventory Sort', 2, 2, 'published_count', 'desc' );
		$name_order  = $this->query->find( Taxonomy::CATEGORY, 'Inventory Sort', 1, 3, 'name', 'desc' );
		$unused      = $this->query->find( Taxonomy::CATEGORY, 'Inventory Sort', 1, 50, 'name', 'asc', true );

		$this->assertSame( 3, $first_page['total'] );
		$this->assertSame( 2, $first_page['total_pages'] );
		$this->assertSame( $charlie_id, $first_page['items'][0]['term_id'] );
		$this->assertSame( $bravo_id, $first_page['items'][1]['term_id'] );
		$this->assertSame( $alpha_id, $second_page['items'][0]['term_id'] );
		$this->assertSame( $charlie_id, $name_order['items'][0]['term_id'] );
		$this->assertSame( 1, $unused['total'] );
		$this->assertSame( $alpha_id, $unused['items'][0]['term_id'] );
	}

	/**
	 * The intended category and tag volumes stay bounded by server pagination.
	 */
	public function test_large_inventory_returns_only_the_requested_page(): void {
		for ( $index = 1; $index <= 100; ++$index ) {
			self::factory()->term->create(
				array(
					'taxonomy' => Taxonomy::CATEGORY->value,
					'name'     => sprintf( 'Inventory Scale Category %03d', $index ),
				)
			);
		}

		for ( $index = 1; $index <= 1000; ++$index ) {
			self::factory()->term->create(
				array(
					'taxonomy' => Taxonomy::POST_TAG->value,
					'name'     => sprintf( 'Inventory Scale Tag %04d', $index ),
				)
			);
		}

		$categories = $this->query->find( Taxonomy::CATEGORY, 'Inventory Scale Category', 1, 50 );
		$tags       = $this->query->find( Taxonomy::POST_TAG, 'Inventory Scale Tag', 20, 50 );

		$this->assertSame( 100, $categories['total'] );
		$this->assertCount( 50, $categories['items'] );
		$this->assertSame( 1000, $tags['total'] );
		$this->assertSame( 20, $tags['total_pages'] );
		$this->assertCount( 50, $tags['items'] );
	}

	/**
	 * Creates a category with a stable slug derived by WordPress.
	 *
	 * @param string $name Category name.
	 */
	private function create_category( string $name ): int {
		return self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::CATEGORY->value,
				'name'     => $name,
			)
		);
	}

	/**
	 * Assigns a term using the WordPress taxonomy API.
	 *
	 * @param int      $object_id WordPress object ID.
	 * @param int      $term_id   Term ID.
	 * @param Taxonomy $taxonomy  Core taxonomy.
	 */
	private function assign_term( int $object_id, int $term_id, Taxonomy $taxonomy ): void {
		$result = wp_set_object_terms( $object_id, $term_id, $taxonomy->value, true );

		$this->assertNotWPError(
			$result,
			sprintf( 'Failed assigning term %d to object %d.', $term_id, $object_id )
		);
	}

	/**
	 * Returns the single inventory row matching a unique slug search.
	 *
	 * @param Taxonomy $taxonomy Taxonomy to search.
	 * @param string   $search   Unique slug search.
	 * @return array<string, int|string|null>
	 */
	private function find_one( Taxonomy $taxonomy, string $search ): array {
		$result = $this->query->find( $taxonomy, $search );

		$this->assertSame( 1, $result['total'] );

		return $result['items'][0];
	}
}
