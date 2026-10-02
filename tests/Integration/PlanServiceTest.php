<?php
/**
 * Phase 4 plan validation and preview integration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use TermSteward\Application\Planning\PlanService;
use TermSteward\Application\Planning\PlanValidationException;
use TermSteward\Application\Planning\PlanWorkflow;
use TermSteward\Domain\Operation\Status;
use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Infrastructure\Database\Schema;
use TermSteward\Infrastructure\Database\Tables;
use TermSteward\Infrastructure\Persistence\OperationRepository;
use WP_Term;
use WP_UnitTestCase;

/**
 * Verifies every Phase 4 validation rule against WordPress data.
 */
final class PlanServiceTest extends WP_UnitTestCase {
	/**
	 * Read-only plan service.
	 *
	 * @var PlanService
	 */
	private PlanService $plans;

	/**
	 * Starts with installed, empty plugin persistence.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->plans = new PlanService();
		$this->delete_operations();
	}

	/**
	 * Removes test operation records.
	 */
	public function tear_down(): void {
		$this->delete_operations();
		parent::tear_down();
	}

	/**
	 * Rename supports name-only and explicit slug plans without changing data.
	 */
	public function test_rename_name_and_explicit_slug_are_read_only(): void {
		$term_id = $this->term( 'category', 'Original name', 'original-name' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assign( $post_id, $term_id, 'category' );

		$name_only = $this->plans->normalize(
			Taxonomy::CATEGORY,
			array( $this->item( 'rename', array( $term_id ), array( 'new_name' => 'Changed name' ) ) )
		);
		$this->assertSame( 'Changed name', $name_only[0]['new_name'] );
		$this->assertNull( $name_only[0]['new_slug'] );

		$with_slug = $this->plans->preview(
			Taxonomy::CATEGORY,
			array(
				$this->item(
					'rename',
					array( $term_id ),
					array(
						'new_name' => 'Changed name',
						'new_slug' => 'changed-name',
					)
				),
			)
		);
		$stored    = get_term( $term_id, 'category' );
		$this->assertInstanceOf( WP_Term::class, $stored );
		$this->assertSame( 'Original name', $stored->name );
		$this->assertSame( 'original-name', $stored->slug );
		$this->assertSame( array( $term_id ), wp_get_post_categories( $post_id ) );
		$this->assertSame( 'changed-name', $with_slug['plan'][0]['new_slug'] );

		$same_slug = $this->plans->normalize(
			Taxonomy::CATEGORY,
			array(
				$this->item(
					'rename',
					array( $term_id ),
					array(
						'new_name' => 'Another name',
						'new_slug' => 'original-name',
					)
				),
			)
		);
		$this->assertNull( $same_slug[0]['new_slug'] );
	}

	/**
	 * Rename rejects empty, invalid, unchanged, and conflicting values.
	 */
	public function test_rename_rejects_invalid_and_conflicting_values(): void {
		$source = $this->term( 'post_tag', 'Source', 'source' );
		$this->term( 'post_tag', 'Existing', 'existing' );

		$this->assert_plan_error( 'new_name_required', Taxonomy::POST_TAG, $this->item( 'rename', array( $source ), array( 'new_name' => '' ) ) );
		$this->assert_plan_error(
			'invalid_slug',
			Taxonomy::POST_TAG,
			$this->item(
				'rename',
				array( $source ),
				array(
					'new_name' => 'Valid',
					'new_slug' => 'Not Valid',
				)
			)
		);
		$this->assert_plan_error( 'name_conflict', Taxonomy::POST_TAG, $this->item( 'rename', array( $source ), array( 'new_name' => 'Existing' ) ) );
		$this->assert_plan_error(
			'slug_conflict',
			Taxonomy::POST_TAG,
			$this->item(
				'rename',
				array( $source ),
				array(
					'new_name' => 'Valid',
					'new_slug' => 'existing',
				)
			)
		);
		$this->assert_plan_error( 'name_unchanged', Taxonomy::POST_TAG, $this->item( 'rename', array( $source ), array( 'new_name' => 'Source' ) ) );
	}

	/**
	 * Merge accepts one or many sources and deduplicates shared published targets.
	 */
	public function test_merge_normalizes_multiple_sources_and_shared_posts(): void {
		$first       = $this->term( 'post_tag', 'First', 'first' );
		$second      = $this->term( 'post_tag', 'Second', 'second' );
		$destination = $this->term( 'post_tag', 'Destination', 'destination' );
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assign( $post_id, array( $first, $second, $destination ), 'post_tag' );

		$raw         = array( $this->merge_item( array( $first, $second ), $destination ) );
		$normalized  = $this->plans->normalize( Taxonomy::POST_TAG, $raw );
		$fingerprint = $this->plans->state_fingerprint( Taxonomy::POST_TAG, $normalized );
		$preview     = $this->plans->preview( Taxonomy::POST_TAG, $raw );

		$this->assertCount( 2, $preview['plan'][0]['sources'] );
		$this->assertSame( array( $post_id ), $preview['target_post_ids'] );
		$this->assertSame( array( $post_id ), $preview['items'][0]['target_post_ids'] );
		$reversed_sources = $this->plans->normalize( Taxonomy::POST_TAG, array( $this->merge_item( array( $second, $first ), $destination ) ) );
		$this->assertSame( $this->plans->plan_hash( $normalized ), $this->plans->plan_hash( $reversed_sources ) );
		$this->assertTrue( $this->plans->is_current( Taxonomy::POST_TAG, $normalized, $fingerprint ) );
	}

	/**
	 * Merge rejects self, cross-taxonomy, duplicates, and plan conflicts.
	 */
	public function test_merge_rejects_identity_taxonomy_and_plan_conflicts(): void {
		$source      = $this->term( 'post_tag', 'Source', 'source' );
		$destination = $this->term( 'post_tag', 'Destination', 'destination' );
		$category    = $this->term( 'category', 'Category destination', 'category-destination' );

		$this->assert_plan_error( 'same_source_destination', Taxonomy::POST_TAG, $this->merge_item( array( $source ), $source ) );
		$this->assert_plan_error( 'taxonomy_mismatch', Taxonomy::POST_TAG, $this->merge_item( array( $source ), $category ) );
		$this->assert_plan_error( 'selection_invalid', Taxonomy::POST_TAG, $this->merge_item( array( $source, $source ), $destination ) );

		$rename = $this->item( 'rename', array( $source ), array( 'new_name' => 'Renamed source' ) );
		$this->assert_plan_errors(
			array( 'plan_conflict' ),
			Taxonomy::POST_TAG,
			array( $rename, $this->merge_item( array( $source ), $destination ) )
		);
		$this->assert_plan_errors(
			array( 'plan_conflict' ),
			Taxonomy::POST_TAG,
			array( $this->merge_item( array( $source ), $destination ), $this->item( 'rename', array( $destination ), array( 'new_name' => 'Changed destination' ) ) )
		);
	}

	/**
	 * Category merge refuses descendants and retains parent sources.
	 */
	public function test_category_merge_rejects_descendants_and_retains_sources_with_children(): void {
		$parent      = $this->term( 'category', 'Parent', 'parent' );
		$child       = $this->term( 'category', 'Child', 'child', $parent );
		$destination = $this->term( 'category', 'Destination', 'destination' );

		$this->assert_plan_error( 'descendant_destination', Taxonomy::CATEGORY, $this->merge_item( array( $parent ), $child ) );
		$preview = $this->plans->preview( Taxonomy::CATEGORY, array( $this->merge_item( array( $parent ), $destination ) ) );
		$this->assertFalse( $preview['items'][0]['sources'][0]['delete_source'] );
		$this->assertContains( 'has_child_categories', $preview['items'][0]['warnings'] );
		$this->assertSame( $parent, (int) get_term( $child, 'category' )->parent );
	}

	/**
	 * Excluded objects are counted and retained but never become fixed targets.
	 */
	public function test_merge_excludes_nonpublished_and_nonstandard_objects(): void {
		register_post_type( 'tidy_fixture', array( 'public' => false ) );
		register_taxonomy_for_object_type( 'post_tag', 'tidy_fixture' );
		$source      = $this->term( 'post_tag', 'Source', 'source' );
		$destination = $this->term( 'post_tag', 'Destination', 'destination' );
		$published   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$excluded    = array(
			self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			self::factory()->post->create( array( 'post_status' => 'private' ) ),
			self::factory()->post->create(
				array(
					'post_status' => 'future',
					'post_date'   => '2030-01-01 00:00:00',
				)
			),
			self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
				)
			),
			self::factory()->post->create(
				array(
					'post_type'   => 'tidy_fixture',
					'post_status' => 'publish',
				)
			),
		);
		$this->assign( $published, $source, 'post_tag' );
		foreach ( $excluded as $post_id ) {
			$this->assign( $post_id, $source, 'post_tag' );
		}

		$preview = $this->plans->preview( Taxonomy::POST_TAG, array( $this->merge_item( array( $source ), $destination ) ) );
		$this->assertSame( array( $published ), $preview['target_post_ids'] );
		$this->assertSame( 5, $preview['items'][0]['sources'][0]['excluded_count'] );
		$this->assertFalse( $preview['items'][0]['sources'][0]['delete_source'] );
		$this->assertContains( 'used_by_excluded_objects', $preview['items'][0]['warnings'] );
		unregister_post_type( 'tidy_fixture' );
	}

	/**
	 * Delete accepts only globally unused non-default terms without a redundant checkbox.
	 */
	public function test_delete_enforces_global_usage_and_default_category(): void {
		$unused   = $this->term( 'category', 'Unused', 'unused' );
		$used     = $this->term( 'category', 'Draft used', 'draft-used' );
		$draft    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$page     = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$page_tag = $this->term( 'post_tag', 'Page used', 'page-used' );
		$this->assign( $draft, $used, 'category' );
		$this->assign( $page, $page_tag, 'post_tag' );

		$valid = $this->plans->preview( Taxonomy::CATEGORY, array( $this->item( 'delete', array( $unused ) ) ) );
		$this->assertTrue( $valid['items'][0]['sources'][0]['delete_source'] );
		$this->assertInstanceOf( WP_Term::class, get_term( $unused, 'category' ) );
		$this->assert_plan_error( 'term_in_use', Taxonomy::CATEGORY, $this->item( 'delete', array( $used ) ) );
		$this->assert_plan_error( 'term_in_use', Taxonomy::POST_TAG, $this->item( 'delete', array( $page_tag ) ) );

		$default = (int) get_option( 'default_category' );
		$this->assert_plan_error( 'default_category', Taxonomy::CATEGORY, $this->item( 'delete', array( $default ) ) );
	}

	/**
	 * Hashes are stable while any relevant state change invalidates fingerprints.
	 */
	public function test_hash_and_fingerprint_detect_relevant_changes(): void {
		$first    = $this->term( 'category', 'First', 'first' );
		$second   = $this->term( 'category', 'Second', 'second' );
		$plan     = $this->plans->normalize(
			Taxonomy::CATEGORY,
			array(
				$this->item( 'rename', array( $first ), array( 'new_name' => 'First new' ) ),
				$this->item( 'rename', array( $second ), array( 'new_name' => 'Second new' ) ),
			)
		);
		$reversed = $this->plans->normalize( Taxonomy::CATEGORY, array_reverse( $plan ) );
		$this->assertSame( $this->plans->plan_hash( $plan ), $this->plans->plan_hash( $reversed ) );

		$fingerprint = $this->plans->state_fingerprint( Taxonomy::CATEGORY, $plan );
		$this->assertTrue( $this->plans->is_current( Taxonomy::CATEGORY, $plan, $fingerprint ) );
		wp_update_term( $first, 'category', array( 'name' => 'Externally renamed' ) );
		$this->assertFalse( $this->plans->is_current( Taxonomy::CATEGORY, $plan, $fingerprint ) );

		$plan        = $this->plans->normalize( Taxonomy::CATEGORY, array( $this->item( 'rename', array( $second ), array( 'new_name' => 'Second latest' ) ) ) );
		$fingerprint = $this->plans->state_fingerprint( Taxonomy::CATEGORY, $plan );
		wp_update_term( $second, 'category', array( 'slug' => 'second-external' ) );
		$this->assertFalse( $this->plans->is_current( Taxonomy::CATEGORY, $plan, $fingerprint ) );

		$plan        = $this->plans->normalize( Taxonomy::CATEGORY, array( $this->item( 'rename', array( $second ), array( 'new_name' => 'Second final' ) ) ) );
		$fingerprint = $this->plans->state_fingerprint( Taxonomy::CATEGORY, $plan );
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assign( $post_id, $second, 'category' );
		$this->assertFalse( $this->plans->is_current( Taxonomy::CATEGORY, $plan, $fingerprint ) );

		$fingerprint = $this->plans->state_fingerprint( Taxonomy::CATEGORY, $plan );
		update_option( 'default_category', $first );
		$this->assertFalse( $this->plans->is_current( Taxonomy::CATEGORY, $plan, $fingerprint ) );
	}

	/**
	 * Draft persistence moves to previewed and edits invalidate the preview.
	 */
	public function test_workflow_persists_fixed_targets_and_invalidates_preview_on_edit(): void {
		global $wpdb;

		$user_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source      = $this->term( 'post_tag', 'Source', 'source' );
		$destination = $this->term( 'post_tag', 'Destination', 'destination' );
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assign( $post_id, $source, 'post_tag' );
		$workflow = new PlanWorkflow( new OperationRepository( $wpdb ), $this->plans );
		$draft    = $workflow->add( $user_id, Taxonomy::POST_TAG, $this->merge_item( array( $source ), $destination ) );
		$this->assertSame( Status::DRAFT->value, $draft['status'] );

		$previewed = $workflow->preview( $user_id, Taxonomy::POST_TAG );
		$this->assertSame( Status::PREVIEWED->value, $previewed['status'] );
		$this->assertSame( array( $post_id ), $previewed['requested_data']['preview']['target_post_ids'] );
		$this->assertNotEmpty( $previewed['plan_hash'] );
		$this->assertNotEmpty( $previewed['state_fingerprint'] );
		wp_update_term( $source, 'post_tag', array( 'name' => 'Externally changed source' ) );
		$this->assertFalse( $workflow->current( $user_id, Taxonomy::POST_TAG )['preview_current'] );

		$other   = $this->term( 'post_tag', 'Other source', 'other-source' );
		$revised = $workflow->add( $user_id, Taxonomy::POST_TAG, $this->item( 'rename', array( $other ), array( 'new_name' => 'Other renamed' ) ) );
		$this->assertSame( Status::DRAFT->value, $revised['status'] );
		$this->assertNull( $revised['plan_hash'] );
		$this->assertNull( $revised['state_fingerprint'] );
		$this->assertCount( 2, $revised['requested_data']['plan'] );
		$this->assertNull( $workflow->current( $user_id, Taxonomy::CATEGORY ) );

		$workflow->preview( $user_id, Taxonomy::POST_TAG );
		$reopened = $workflow->revise( $user_id, Taxonomy::POST_TAG );
		$this->assertSame( Status::DRAFT->value, $reopened['status'] );
		$workflow->discard( $user_id, Taxonomy::POST_TAG );
		$this->assertNull( $workflow->current( $user_id, Taxonomy::POST_TAG ) );
	}

	/**
	 * Execute-style preview validates before persistence and leaves invalid state untouched.
	 */
	public function test_preview_with_item_does_not_persist_invalid_input(): void {
		global $wpdb;

		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source   = $this->term( 'post_tag', 'Preview source', 'preview-source' );
		$workflow = new PlanWorkflow( new OperationRepository( $wpdb ), $this->plans );

		try {
			$workflow->preview_with_item( $user_id, Taxonomy::POST_TAG, $this->item( 'rename', array( $source ), array( 'new_name' => '' ) ) );
			$this->fail( 'Invalid input should not create a preview.' );
		} catch ( PlanValidationException $exception ) {
			$this->assertContains( 'new_name_required', $exception->codes() );
		}

		$this->assertNull( $workflow->current( $user_id, Taxonomy::POST_TAG ) );
		$this->assertSame( 'Preview source', get_term( $source, 'post_tag' )->name );
	}

	/**
	 * Creates a raw item carrying server-verifiable taxonomy IDs.
	 *
	 * @param string               $action     Action value.
	 * @param array                $source_ids Source term IDs.
	 * @param array<string, mixed> $extra      Action-specific data.
	 * @return array<string, mixed>
	 */
	private function item( string $action, array $source_ids, array $extra = array() ): array {
		$taxonomy_ids = array();
		foreach ( $source_ids as $term_id ) {
			$term                     = get_term( $term_id );
			$taxonomy_ids[ $term_id ] = $term instanceof WP_Term ? (int) $term->term_taxonomy_id : 0;
		}
		return array_merge(
			array(
				'action'        => $action,
				'source_ids'    => $source_ids,
				'source_tt_ids' => $taxonomy_ids,
			),
			$extra
		);
	}

	/**
	 * Creates a raw merge item.
	 *
	 * @param array $source_ids    Source term IDs.
	 * @param int   $destination_id Destination term ID.
	 * @return array<string, mixed>
	 */
	private function merge_item( array $source_ids, int $destination_id ): array {
		$destination = get_term( $destination_id );
		return $this->item(
			'merge',
			$source_ids,
			array(
				'destination_id'    => $destination_id,
				'destination_tt_id' => $destination instanceof WP_Term ? (int) $destination->term_taxonomy_id : 0,
			)
		);
	}

	/**
	 * Creates a taxonomy term and returns its ID.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $name     Term name.
	 * @param string $slug     Term slug.
	 * @param int    $parent_id Optional parent term ID.
	 * @return int
	 */
	private function term( string $taxonomy, string $name, string $slug, int $parent_id = 0 ): int {
		return self::factory()->term->create(
			array(
				'taxonomy' => $taxonomy,
				'name'     => $name,
				'slug'     => $slug,
				'parent'   => $parent_id,
			)
		);
	}

	/**
	 * Assigns terms and verifies the WordPress API result.
	 *
	 * @param int       $object_id Object ID.
	 * @param int|array $term_ids  Term IDs.
	 * @param string    $taxonomy  Taxonomy name.
	 */
	private function assign( int $object_id, int|array $term_ids, string $taxonomy ): void {
		$this->assertNotWPError(
			wp_set_object_terms( $object_id, $term_ids, $taxonomy ),
			sprintf( 'Assignment failed for object %d in %s.', $object_id, $taxonomy )
		);
	}

	/**
	 * Asserts one validation code for a single item.
	 *
	 * @param string               $code     Expected code.
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Raw plan item.
	 */
	private function assert_plan_error( string $code, Taxonomy $taxonomy, array $item ): void {
		$this->assert_plan_errors( array( $code ), $taxonomy, array( $item ) );
	}

	/**
	 * Asserts that normalization contains expected validation codes.
	 *
	 * @param array    $codes    Expected codes.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param array    $items    Raw plan items.
	 */
	private function assert_plan_errors( array $codes, Taxonomy $taxonomy, array $items ): void {
		try {
			$this->plans->normalize( $taxonomy, $items );
			$this->fail( 'Expected plan validation to fail.' );
		} catch ( PlanValidationException $exception ) {
			foreach ( $codes as $code ) {
				$this->assertContains( $code, $exception->codes() );
			}
		}
	}

	/**
	 * Clears only plugin operation tables in the isolated test database.
	 */
	private function delete_operations(): void {
		global $wpdb;

		foreach ( array( Tables::changes( $wpdb ), Tables::items( $wpdb ), Tables::operations( $wpdb ) ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated custom test tables are intentionally cleared.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}
