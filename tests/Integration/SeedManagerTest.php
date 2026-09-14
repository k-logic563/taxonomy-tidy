<?php
/**
 * Local-development seed manager integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use RuntimeException;
use TaxonomyTidy\Development\SeedManager;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Taxonomy\TermInventoryQuery;
use WP_Term;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/tools/seed/SeedManager.php';

/**
 * Verifies deterministic creation and ownership-safe cleanup of seed fixtures.
 */
final class SeedManagerTest extends WP_UnitTestCase {
	/**
	 * Non-local environments cannot execute any seed command.
	 */
	public function test_rejects_non_local_environment(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'available only when WP_ENVIRONMENT_TYPE is local' );

		( new SeedManager() )->execute( 'demo', 'production' );
	}

	/**
	 * Demo creation is idempotent and cleanup preserves unregistered objects.
	 */
	public function test_demo_is_idempotent_and_cleanup_is_scoped_to_seed_objects(): void {
		global $wpdb;

		$existing_post_id     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$existing_category_id = self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::CATEGORY->value,
				'name'     => 'Existing category',
			)
		);
		$existing_tag_id      = self::factory()->term->create(
			array(
				'taxonomy' => Taxonomy::POST_TAG->value,
				'name'     => 'Existing tag',
			)
		);
		$manager              = new SeedManager();

		$first  = $manager->execute( 'demo', 'local' );
		$second = $manager->execute( 'demo', 'local' );

		$this->assertSame( $first, $second );
		$this->assertSame(
			array(
				'mode'       => 'demo',
				'posts'      => 115,
				'categories' => 25,
				'tags'       => 75,
			),
			$second
		);

		$registry = get_option( SeedManager::REGISTRY_OPTION );
		$this->assertIsArray( $registry );
		$this->assertCount( 115, $registry['posts'] );
		$this->assertCount( 25, $registry['terms']['category'] );
		$this->assertCount( 75, $registry['terms']['post_tag'] );
		$this->assertSame(
			array(
				'publish:post' => 90,
				'draft:post'   => 10,
				'private:post' => 5,
				'future:post'  => 5,
				'publish:page' => 5,
			),
			$this->post_counts( $registry['posts'] )
		);

		$query = new TermInventoryQuery( $wpdb );
		$this->assertSame( array( 1, 1 ), $this->usage_counts( $query, $registry, 'published_only' ) );
		$this->assertSame( array( 1, 2 ), $this->usage_counts( $query, $registry, 'published_draft' ) );
		$this->assertSame( array( 0, 1 ), $this->usage_counts( $query, $registry, 'draft_only' ) );
		$this->assertSame( array( 0, 2 ), $this->usage_counts( $query, $registry, 'excluded_only' ) );
		$this->assertSame( array( 0, 0 ), $this->usage_counts( $query, $registry, 'unused' ) );
		$this->assertSame( array( 1, 1 ), $this->usage_counts( $query, $registry, 'merge_source' ) );
		$this->assertSame( array( 1, 1 ), $this->usage_counts( $query, $registry, 'merge_target' ) );

		$unused_tag_id = (int) $registry['terms']['post_tag']['unused'];
		$this->assertNotWPError( wp_set_object_terms( $existing_post_id, $unused_tag_id, Taxonomy::POST_TAG->value, true ) );

		try {
			$manager->execute( 'clean', 'local' );
			$this->fail( 'Cleanup did not reject a seed term attached to a non-seed post.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'No data was deleted.', $exception->getMessage() );
			$this->assertNotNull( get_post( (int) $registry['posts']['published_001'] ) );
		}

		$this->assertTrue( wp_remove_object_terms( $existing_post_id, $unused_tag_id, Taxonomy::POST_TAG->value ) );
		$cleaned = $manager->execute( 'clean', 'local' );
		$this->assertSame( 115, $cleaned['posts'] );
		$this->assertSame( 25, $cleaned['categories'] );
		$this->assertSame( 75, $cleaned['tags'] );
		$this->assertNotNull( get_post( $existing_post_id ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $existing_category_id, Taxonomy::CATEGORY->value ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $existing_tag_id, Taxonomy::POST_TAG->value ) );
		$this->assertFalse( get_option( SeedManager::REGISTRY_OPTION, false ) );
		$this->assertSame(
			array(
				'mode'       => 'clean',
				'posts'      => 0,
				'categories' => 0,
				'tags'       => 0,
			),
			$manager->execute( 'clean', 'local' )
		);
	}

	/**
	 * Counts registered posts by status and type.
	 *
	 * @param array<string, int> $post_ids Registered post IDs by fixture key.
	 * @return array<string, int>
	 */
	private function post_counts( array $post_ids ): array {
		$counts = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			$this->assertNotNull( $post );
			$key            = $post->post_status . ':' . $post->post_type;
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Returns the published and total relationship counts for a fixture tag.
	 *
	 * @param TermInventoryQuery   $query    Inventory query.
	 * @param array<string, mixed> $registry Seed registry.
	 * @param string               $key      Tag fixture key.
	 * @return array{int, int}
	 */
	private function usage_counts( TermInventoryQuery $query, array $registry, string $key ): array {
		$term_id = (int) $registry['terms']['post_tag'][ $key ];
		$term    = get_term( $term_id, Taxonomy::POST_TAG->value );
		$this->assertInstanceOf( WP_Term::class, $term );
		$result = $query->find( Taxonomy::POST_TAG, $term->slug );

		foreach ( $result['items'] as $item ) {
			if ( $term_id === $item['term_id'] ) {
				return array( $item['published_post_count'], $item['total_relationship_count'] );
			}
		}

		$this->fail( sprintf( 'Fixture tag %s was not found in the inventory.', $key ) );
	}
}
