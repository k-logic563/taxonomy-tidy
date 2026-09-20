<?php
/**
 * Admin page access integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use RuntimeException;
use TaxonomyTidy\Admin\Access;
use TaxonomyTidy\Admin\Page;
use TaxonomyTidy\Admin\PlanController;
use TaxonomyTidy\Application\Planning\PlanService;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;
use WP_UnitTestCase;

/**
 * Verifies the access policy and read-only taxonomy screen.
 */
final class AdminPageTest extends WP_UnitTestCase {
	/**
	 * Query parameters present before each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get;

	/**
	 * Posted values present before each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_post;

	/**
	 * Request method present before each test.
	 *
	 * @var string|null
	 */
	private ?string $original_method;

	/**
	 * Admin page under test.
	 *
	 * @var Page
	 */
	private Page $page;

	/**
	 * Creates a fresh page service for each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->page = new Page();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The test preserves read-only query state; it does not process a request.
		$this->original_get = $_GET;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The test preserves request state and restores it after each test.
		$this->original_post = $_POST;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test setup preserves a server value and never renders it.
		$this->original_method     = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;
		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/**
	 * Resets the current user after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_GET  = $this->original_get;
		$_POST = $this->original_post;
		if ( null === $this->original_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->original_method;
		}
		parent::tear_down();
	}

	/**
	 * Administrators have all three required capabilities and can render the page.
	 */
	public function test_authorized_user_can_render_page(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( Access::current_user_can_access() );

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Taxonomy Tidy</h1>', $output );
		$this->assertStringContainsString( 'nav-tab-wrapper', $output );
		$this->assertStringContainsString( '<details class="taxonomy-tidy-panel taxonomy-tidy-filter-panel">', $output );
		$this->assertStringContainsString( '<details class="taxonomy-tidy-panel taxonomy-tidy-process-panel" >', $output );
		$this->assertSame( 2, substr_count( $output, 'class="taxonomy-tidy-panel__summary" aria-expanded="false"' ) );
		$this->assertStringContainsString( 'class="taxonomy-tidy-table-scroll" tabindex="0" role="region"', $output );
		$this->assertStringContainsString( 'class="taxonomy-tidy-number"', $output );
		$this->assertStringContainsString( 'Search panel', $output );
		$this->assertStringContainsString( 'Action panel', $output );
		$this->assertStringContainsString( 'No active conditions', $output );
		$this->assertStringContainsString( '<label for="taxonomy-tidy-search">Keyword</label>', $output );
		$this->assertStringContainsString( 'aria-describedby="taxonomy-tidy-search-description"', $output );
		$this->assertStringContainsString( '<label for="taxonomy-tidy-orderby">Sort by</label>', $output );
		$this->assertStringContainsString( '<label for="taxonomy-tidy-order">Direction</label>', $output );
		$this->assertStringContainsString( 'Apply conditions', $output );
		$this->assertStringContainsString( 'Reset conditions', $output );
		$this->assertSame( 4, substr_count( $output, '<form ' ) );
		$this->assertStringContainsString( 'class="taxonomy-tidy-select-page"', $output );
		$this->assertStringContainsString( 'No terms selected', $output );
		$this->assertStringContainsString( '0 processes configured', $output );
		$this->assertStringContainsString( 'Published posts', $output );
		$this->assertStringContainsString( 'Total relationships', $output );
		$this->assertStringContainsString( 'Selected targets', $output );
		$this->assertStringContainsString( 'Action method', $output );
		$this->assertStringContainsString( 'Changes', $output );
		$this->assertStringContainsString( 'Notices and validation results', $output );
		$this->assertStringContainsString( 'name="plan_command" value="add">計画に追加</button>', $output );
		$this->assertStringContainsString( 'id="taxonomy-tidy-merge-source-group" class="taxonomy-tidy-field-group taxonomy-tidy-readonly-field"', $output );
		$this->assertStringContainsString( 'class="taxonomy-tidy-field-display taxonomy-tidy-merge-sources"', $output );
		$this->assertStringContainsString( 'id="taxonomy-tidy-merge-destination-group" class="taxonomy-tidy-field-group"', $output );
		$this->assertStringContainsString( '<label class="taxonomy-tidy-field-label" for="taxonomy-tidy-destination">Merge destination</label>', $output );
		$this->assertStringContainsString( 'id="taxonomy-tidy-destination-help" class="description taxonomy-tidy-field-help"', $output );
		$this->assertStringContainsString( '<select class="taxonomy-tidy-field-control tt-control" id="taxonomy-tidy-destination" name="destination"', $output );
		$this->assertStringContainsString( 'class="wrap taxonomy-tidy taxonomy-tidy-screen"', $output );
		$this->assertStringContainsString( 'class="button button-primary tt-button tt-button--primary"', $output );
		$this->assertStringContainsString( '<option value="">Select a merge destination.</option>', $output );
		$this->assertStringNotContainsString( '<datalist', $output );
		$this->assertStringNotContainsString( 'taxonomy-tidy-destination-results', $output );
		$this->assertStringContainsString( '<span class="taxonomy-tidy-field-label">Deletion targets</span>', $output );
		$this->assertStringNotContainsString( 'taxonomy-tidy-actions-heading', $output );
		$this->assertStringNotContainsString( 'name="change_slug"', $output );
		$this->assertStringNotContainsString( 'name="delete_confirmed"', $output );
		$this->assertStringNotContainsString( 'Execute', $output );

		$search_position = strpos( $output, 'taxonomy-tidy-filter-panel' );
		$action_position = strpos( $output, 'taxonomy-tidy-process-panel' );
		$table_position  = strpos( $output, 'taxonomy-tidy-inventory-table' );
		$this->assertIsInt( $search_position );
		$this->assertIsInt( $action_position );
		$this->assertIsInt( $table_position );
		$this->assertLessThan( $action_position, $search_position );
		$this->assertLessThan( $table_position, $action_position );

		$target_group_position     = strpos( $output, 'taxonomy-tidy-target-heading' );
		$method_group_position     = strpos( $output, '<h3 id="taxonomy-tidy-operation-heading">Action method</h3>' );
		$changes_group_position    = strpos( $output, 'taxonomy-tidy-change-heading' );
		$validation_group_position = strpos( $output, 'taxonomy-tidy-validation-heading' );
		$execute_position          = strpos( $output, 'name="plan_command" value="add"' );
		$this->assertIsInt( $target_group_position );
		$this->assertIsInt( $method_group_position );
		$this->assertIsInt( $changes_group_position );
		$this->assertIsInt( $validation_group_position );
		$this->assertIsInt( $execute_position );
		$this->assertLessThan( $method_group_position, $target_group_position );
		$this->assertLessThan( $changes_group_position, $method_group_position );
		$this->assertLessThan( $validation_group_position, $changes_group_position );
		$this->assertLessThan( $execute_position, $validation_group_position );
		$this->assertMatchesRegularExpression( '/class="taxonomy-tidy-process-group taxonomy-tidy-validation"[^>]+hidden>/', $output );
	}

	/** All same-taxonomy destinations are rendered once regardless of inventory paging. */
	public function test_large_tag_inventory_renders_all_destination_candidates_once(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		for ( $index = 1; $index <= 1000; ++$index ) {
			self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => sprintf( 'Full candidate %04d', $index ),
				)
			);
		}
		$_GET = array(
			'taxonomy' => 'post_tag',
			'per_page' => '20',
			's'        => 'Full candidate 0001',
		);

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '>Full candidate 0001</option>', $output );
		$this->assertStringContainsString( '>Full candidate 1000</option>', $output );
		$this->assertSame( 1, substr_count( $output, '>Full candidate 1000</option>' ) );
		$this->assertSame( 1, preg_match( '/<select[^>]+id="taxonomy-tidy-destination"[^>]*>(.*?)<\/select>/s', $output, $destination_select ) );
		$this->assertSame( 1000, substr_count( $destination_select[1], 'data-term-key="' ) );
		$this->assertLessThan( 328 * 1024, strlen( $output ) );
	}

	/** Destination candidates never cross taxonomy boundaries. */
	public function test_destination_candidates_are_limited_to_current_taxonomy(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Category destination only',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Tag destination only',
			)
		);

		$_GET = array( 'taxonomy' => 'category' );
		ob_start();
		$this->page->render();
		$category_output = (string) ob_get_clean();
		$this->assertStringContainsString( '>Category destination only</option>', $category_output );
		$this->assertStringNotContainsString( '>Tag destination only</option>', $category_output );

		$_GET = array( 'taxonomy' => 'post_tag' );
		ob_start();
		$this->page->render();
		$tag_output = (string) ob_get_clean();
		$this->assertStringContainsString( '>Tag destination only</option>', $tag_output );
		$this->assertStringNotContainsString( '>Category destination only</option>', $tag_output );
	}

	/** Duplicate category names are distinguished without exposing internal IDs. */
	public function test_duplicate_category_names_use_parent_paths(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$first_parent  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => '技術',
			)
		);
		$second_parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'お知らせ',
			)
		);
		$first         = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'WordPress',
				'parent'   => $first_parent,
			)
		);
		$second        = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'WordPress',
				'parent'   => $second_parent,
			)
		);
		$first_term    = get_term( $first, 'category' );
		$second_term   = get_term( $second, 'category' );
		$this->assertInstanceOf( \WP_Term::class, $first_term );
		$this->assertInstanceOf( \WP_Term::class, $second_term );

		$_GET = array( 'taxonomy' => 'category' );
		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="' . $first . ':' . $first_term->term_taxonomy_id . '" data-term-key="' . $first . '" >技術 › WordPress</option>', $output );
		$this->assertStringContainsString( 'value="' . $second . ':' . $second_term->term_taxonomy_id . '" data-term-key="' . $second . '" >お知らせ › WordPress</option>', $output );
		$this->assertStringNotContainsString( '>#' . $first . ' WordPress</option>', $output );
	}

	/** Destination options show only escaped names while preserving stable IDs. */
	public function test_destination_options_display_only_names_and_preserve_ids(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Selected source',
			)
		);
		$destination      = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Visible & safe destination',
				'slug'     => 'selected-destination',
			)
		);
		$destination_term = get_term( $destination, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $destination_term );
		$_GET = array( 'taxonomy' => 'post_tag' );

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="' . $destination . ':' . $destination_term->term_taxonomy_id . '" data-term-key="' . $destination . '"', $output );
		$this->assertStringContainsString( '>Visible &amp; safe destination</option>', $output );
		$this->assertStringNotContainsString( '>selected-destination</option>', $output );
		$this->assertStringNotContainsString( 'ID:', $output );
		$this->assertStringNotContainsString( 'term_id', $output );
	}

	/** Four tabs show the current administrator's combined draft count. */
	public function test_plan_tab_groups_two_drafts_and_marks_current_view(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array(
			'category' => 'Tab category',
			'post_tag' => 'Tab tag',
		) as $taxonomy => $name ) {
			$term_id = self::factory()->term->create(
				array(
					'taxonomy' => $taxonomy,
					'name'     => $name,
				)
			);
			$term    = get_term( $term_id, $taxonomy );
			$this->assertInstanceOf( \WP_Term::class, $term );
			$_GET                      = array( 'taxonomy' => $taxonomy );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'taxonomy'                  => $taxonomy,
				'plan_command'              => 'add',
				'operation_action'          => 'rename',
				'selected_terms'            => array( (string) $term_id ),
				'term_taxonomy_ids'         => array( $term_id => (string) $term->term_taxonomy_id ),
				'new_name'                  => $name . ' renamed',
				PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
			);
			ob_start();
			$this->page->render();
			ob_end_clean();
		}
		$_GET                      = array( 'view' => 'plan' );
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();
		$this->assertSame( 4, substr_count( $output, '<a class="nav-tab' ) );
		$this->assertStringContainsString( 'aria-label="操作計画（2件）"', $output );
		$this->assertStringContainsString( 'class="nav-tab nav-tab-active"', $output );
		$this->assertStringContainsString( 'Tab category renamed', $output );
		$this->assertStringContainsString( 'Tab tag renamed', $output );
		$this->assertStringNotContainsString( 'role="dialog"', $output );
		global $wpdb;
		$operation = ( new OperationRepository( $wpdb ) )->find_draft( get_current_user_id(), Taxonomy::CATEGORY );
		$this->assertIsArray( $operation );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'view'                      => 'plan',
			'plan_command'              => 'remove_item',
			'taxonomy'                  => 'category',
			'operation_id'              => (string) $operation['id'],
			'item_index'                => '0',
			'expected_plan_hash'        => ( new PlanService() )->plan_hash( $operation['requested_data']['plan'] ),
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);
		ob_start();
		$this->page->render();
		$after_delete = (string) ob_get_clean();
		$this->assertStringContainsString( 'aria-label="操作計画（1件）"', $after_delete );
		$this->assertStringContainsString( '操作計画から削除しました。', $after_delete );
		$this->assertStringNotContainsString( 'Tab category renamed', $after_delete );
		$this->assertStringContainsString( 'Tab tag renamed', $after_delete );

		$_GET                      = array( 'view' => 'history' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		ob_start();
		$this->page->render();
		$history = (string) ob_get_clean();
		$this->assertStringContainsString( '実行済みの操作はありません。', $history );
		$this->assertStringNotContainsString( 'Tab category renamed', $history );
	}

	/** Parent cells use words instead of ambiguous empty values or punctuation. */
	public function test_parent_column_has_meaningful_values_for_both_taxonomies(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Parent label tag',
			)
		);

		$_GET = array( 'taxonomy' => 'category' );
		ob_start();
		$this->page->render();
		$categories = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-label="Parent category">なし</td>', $categories );

		$_GET = array( 'taxonomy' => 'post_tag' );
		ob_start();
		$this->page->render();
		$tags = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-label="Parent category">対象外</td>', $tags );
	}

	/**
	 * A validation error opens the process panel and preserves submitted input.
	 */
	public function test_process_panel_opens_only_for_validation_errors(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Panel source',
			)
		);
		$term    = get_term( $term_id, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$_GET                      = array( 'taxonomy' => 'post_tag' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'taxonomy'                  => 'post_tag',
			'plan_command'              => 'add',
			'operation_action'          => 'rename',
			'selected_terms'            => array( (string) $term_id ),
			'term_taxonomy_ids'         => array( $term_id => (string) $term->term_taxonomy_id ),
			'new_name'                  => '',
			'new_slug'                  => 'Not Valid',
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '<details class="taxonomy-tidy-panel taxonomy-tidy-process-panel" open>', $output );
		$this->assertStringContainsString( 'class="taxonomy-tidy-panel__summary" aria-expanded="true"', $output );
		$this->assertStringContainsString( 'id="taxonomy-tidy-new-name-error-0"', $output );
		$this->assertStringContainsString( 'aria-invalid="true" aria-describedby="taxonomy-tidy-new-name-error-0"', $output );
		$this->assertStringContainsString( 'Enter a new name.', $output );
		$this->assertStringContainsString( 'id="taxonomy-tidy-new-slug-error-0"', $output );
		$this->assertStringContainsString( 'taxonomy-tidy-new-slug-help taxonomy-tidy-new-slug-error-0', $output );
		$this->assertStringContainsString( 'The slug format is invalid.', $output );
		$this->assertMatchesRegularExpression( '/name="selected_terms\[\]"[^>]+checked=[\'\"]checked[\'\"]/', $output );
		$this->assertMatchesRegularExpression( '/name="operation_action" value="rename"[^>]+checked=[\'\"]checked[\'\"]/', $output );
		$this->assertMatchesRegularExpression( '/id="taxonomy-tidy-new-name"[^>]+data-error-focus="true"/', $output );
		$this->assertStringNotContainsString( 'autofocus', $output );
	}

	/**
	 * A valid preview is confined to a dialog and keeps post titles out of initial markup.
	 */
	public function test_valid_preview_renders_compact_modal_only(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Modal source',
			)
		);
		$term    = get_term( $term_id, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Lazy title fixture',
			)
		);
		wp_set_object_terms( $post_id, $term_id, 'post_tag' );
		$_GET                      = array( 'taxonomy' => 'post_tag' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'taxonomy'                  => 'post_tag',
			'plan_command'              => 'add',
			'operation_action'          => 'rename',
			'selected_terms'            => array( (string) $term_id ),
			'term_taxonomy_ids'         => array( $term_id => (string) $term->term_taxonomy_id ),
			'new_name'                  => 'Modal renamed',
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);
		ob_start();
		$this->page->render();
		ob_end_clean();
		$_GET  = array( 'view' => 'plan' );
		$_POST = array(
			'plan_command'              => 'preview_all',
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);
		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $output, 'role="dialog"' ) );
		$this->assertStringContainsString( 'aria-modal="true"', $output );
		$this->assertStringContainsString( 'data-auto-open="1" data-running="0" hidden', $output );
		$this->assertStringContainsString( 'Modal renamed', $output );
		$this->assertStringContainsString( '対象投稿を確認', $output );
		$this->assertStringNotContainsString( 'Lazy title fixture', $output );
		$this->assertStringNotContainsString( '変更後のslug', $output );
		$this->assertStringNotContainsString( 'Preview created:', $output );
		$this->assertStringNotContainsString( 'Warnings: 0', $output );
		$this->assertStringContainsString( 'class="button tt-button tt-button--secondary taxonomy-tidy-modal__cancel"', $output );
		$this->assertStringContainsString( 'class="taxonomy-tidy taxonomy-tidy-modal taxonomy-tidy-board-modal"', $output );
		$this->assertStringContainsString( 'name="plan_command" value="run_all"', $output );
		$this->assertStringNotContainsString( 'value="discard"', $output );
		$this->assertStringNotContainsString( 'value="revise"', $output );
		$this->assertStringNotContainsString( 'value="run"', substr( $output, 0, strpos( $output, '</form>' ) ) );
	}

	/**
	 * Only an explicitly changed slug appears in the preview.
	 */
	public function test_preview_shows_changed_slug(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Slug source',
				'slug'     => 'slug-source',
			)
		);
		$term    = get_term( $term_id, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$_GET                      = array( 'taxonomy' => 'post_tag' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'taxonomy'                  => 'post_tag',
			'plan_command'              => 'add',
			'operation_action'          => 'rename',
			'selected_terms'            => array( (string) $term_id ),
			'term_taxonomy_ids'         => array( $term_id => (string) $term->term_taxonomy_id ),
			'new_name'                  => 'Slug source renamed',
			'new_slug'                  => 'slug-changed',
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);
		ob_start();
		$this->page->render();
		ob_end_clean();
		$_GET  = array( 'view' => 'plan' );
		$_POST = array(
			'plan_command'              => 'preview_all',
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);
		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Slug source renamed / slug-changed', $output );
		$this->assertStringContainsString( 'slug-changed', $output );
		$this->assertStringNotContainsString( '対象投稿を確認（0件）', $output );
	}

	/**
	 * Selection and action errors render once in their corresponding sections.
	 */
	public function test_execute_groups_multiple_errors_by_section(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'taxonomy'                  => 'category',
			'plan_command'              => 'add',
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '<details class="taxonomy-tidy-panel taxonomy-tidy-process-panel" open>', $output );
		$this->assertStringContainsString( 'class="taxonomy-tidy-panel__summary" aria-expanded="true"', $output );
		$this->assertStringContainsString( 'id="taxonomy-tidy-selection-error-0"', $output );
		$this->assertStringContainsString( 'id="taxonomy-tidy-operation-error-0"', $output );
		$this->assertSame( 1, substr_count( $output, 'Select a category or tag to process.</p>' ) );
		$this->assertSame( 1, substr_count( $output, 'Select an action method.</p>' ) );
		$this->assertStringContainsString( 'id="taxonomy-tidy-selection-error-0" class="taxonomy-tidy-field-error" tabindex="-1"', $output );
		$this->assertStringNotContainsString( 'data-error-focus="true"', $output );
		$this->assertStringNotContainsString( 'id="taxonomy-tidy-selection-section" class="taxonomy-tidy-process-group" aria-labelledby="taxonomy-tidy-target-heading" tabindex', $output );
		$this->assertStringNotContainsString( 'taxonomy-tidy-plan-heading', $output );

		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Action focus source',
			)
		);
		$term    = get_term( $term_id, 'category' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$_POST = array(
			'taxonomy'                  => 'category',
			'plan_command'              => 'add',
			'selected_terms'            => array( (string) $term_id ),
			'term_taxonomy_ids'         => array( $term_id => (string) $term->term_taxonomy_id ),
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);
		ob_start();
		$this->page->render();
		$operation_output = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/id="taxonomy-tidy-action-rename"[^>]+data-error-focus="true"/', $operation_output );
	}

	/**
	 * Merge and delete errors render next to their corresponding change controls.
	 */
	public function test_change_errors_render_inside_change_section(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Inline error source',
			)
		);
		$term    = get_term( $term_id, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$second_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Inline error source',
				'slug'     => 'inline-error-source-2',
			)
		);
		$third_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Inline error source',
				'slug'     => 'inline-error-source-3',
			)
		);
		$second    = get_term( $second_id, 'post_tag' );
		$third     = get_term( $third_id, 'post_tag' );
		$this->assertInstanceOf( \WP_Term::class, $second );
		$this->assertInstanceOf( \WP_Term::class, $third );
		$_GET                      = array( 'taxonomy' => 'post_tag' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'taxonomy'                  => 'post_tag',
			'plan_command'              => 'add',
			'operation_action'          => 'merge',
			'selected_terms'            => array( (string) $term_id ),
			'term_taxonomy_ids'         => array( $term_id => (string) $term->term_taxonomy_id ),
			'destination'               => sprintf( '%1$d:%2$d', $term_id, $term->term_taxonomy_id ),
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);

		ob_start();
		$this->page->render();
		$merge_output = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="taxonomy-tidy-destination-error-0"', $merge_output );
		$this->assertStringContainsString( 'The merge destination cannot be the same as its source.', $merge_output );
		$this->assertStringContainsString( '<ul class="taxonomy-tidy-selected-list">', $merge_output );
		$this->assertStringContainsString( '<li>Inline error source</li>', $merge_output );
		$this->assertStringContainsString( 'aria-describedby="taxonomy-tidy-destination-help taxonomy-tidy-destination-selection-notice taxonomy-tidy-destination-error-0"', $merge_output );
		$this->assertMatchesRegularExpression( '/id="taxonomy-tidy-destination"[^>]+data-error-focus="true"/', $merge_output );
		$this->assertLessThan( strpos( $merge_output, 'taxonomy-tidy-destination-error-0' ), strpos( $merge_output, 'taxonomy-tidy-merge-destination-group' ) );
		$this->assertMatchesRegularExpression( '/<select[^>]+id="taxonomy-tidy-destination"[^>]*>\s*<option value="">/', $merge_output );
		$this->assertStringContainsString( '選択していた統合先が統合元に含まれたため、選択を解除しました。', $merge_output );
		$this->assertSame( 1, preg_match( '/<select[^>]+id="taxonomy-tidy-destination"[^>]*>(.*?)<\/select>/s', $merge_output, $destination_select ) );
		$this->assertStringNotContainsString( 'data-term-key="' . $term_id . '"', $destination_select[1] );
		$this->assertStringContainsString( 'data-term-key="' . $second_id . '"', $destination_select[1] );

		$_POST['selected_terms']                  = array( (string) $term_id, (string) $second_id );
		$_POST['term_taxonomy_ids'][ $second_id ] = (string) $second->term_taxonomy_id;
		ob_start();
		$this->page->render();
		$multiple_source_output = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '/<select[^>]+id="taxonomy-tidy-destination"[^>]*>(.*?)<\/select>/s', $multiple_source_output, $multiple_destination_select ) );
		$this->assertStringNotContainsString( 'data-term-key="' . $term_id . '"', $multiple_destination_select[1] );
		$this->assertStringNotContainsString( 'data-term-key="' . $second_id . '"', $multiple_destination_select[1] );
		$this->assertStringContainsString( 'data-term-key="' . $third_id . '"', $multiple_destination_select[1] );

		unset( $_POST['selected_terms'], $_POST['term_taxonomy_ids'] );
		ob_start();
		$this->page->render();
		$missing_source_output = (string) ob_get_clean();
		$source_group_position = strpos( $missing_source_output, 'taxonomy-tidy-merge-source-group' );
		$source_error_position = strpos( $missing_source_output, 'taxonomy-tidy-merge-source-error-0' );
		$destination_position  = strpos( $missing_source_output, 'taxonomy-tidy-merge-destination-group' );
		$this->assertIsInt( $source_group_position );
		$this->assertIsInt( $source_error_position );
		$this->assertIsInt( $destination_position );
		$this->assertLessThan( $source_error_position, $source_group_position );
		$this->assertLessThan( $destination_position, $source_error_position );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertNotWPError( wp_set_object_terms( $post_id, $term_id, 'post_tag' ) );
		$_POST['operation_action']  = 'delete';
		$_POST['selected_terms']    = array( (string) $term_id );
		$_POST['term_taxonomy_ids'] = array( $term_id => (string) $term->term_taxonomy_id );
		unset( $_POST['destination'] );

		ob_start();
		$this->page->render();
		$delete_output = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="taxonomy-tidy-delete-error-0"', $delete_output );
		$this->assertStringContainsString( 'id="taxonomy-tidy-delete-error-0" class="taxonomy-tidy-field-error" tabindex="-1"', $delete_output );
		$this->assertStringContainsString( 'A category or tag that is in use cannot be deleted.', $delete_output );
		$this->assertSame( 1, preg_match( '/<ul class="taxonomy-tidy-delete-list">(.*?)<\/ul>/s', $delete_output, $delete_targets ) );
		$this->assertStringContainsString( '<li>Inline error source</li>', $delete_targets[1] );
		$this->assertStringNotContainsString( 'Published posts', $delete_targets[1] );
		$this->assertStringNotContainsString( 'Total relationships', $delete_targets[1] );
		$this->assertStringNotContainsString( 'taxonomy-tidy-readonly-field" aria-invalid="true" aria-describedby="taxonomy-tidy-delete-error-0" tabindex', $delete_output );
		$this->assertStringNotContainsString( 'name="delete_confirmed"', $delete_output );
	}

	/**
	 * A submitted GET request preserves all controls and exposes active conditions.
	 */
	public function test_filter_form_preserves_values_and_reset_clears_all_conditions(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_GET = array(
			'page'     => Page::SLUG,
			'taxonomy' => 'post_tag',
			's'        => 'WordPress',
			'orderby'  => 'published_count',
			'order'    => 'desc',
			'unused'   => '1',
			'paged'    => '7',
		);

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="WordPress"', $output );
		$this->assertMatchesRegularExpression( '/value="published_count"\s+selected=[\'\"]selected[\'\"]/', $output );
		$this->assertMatchesRegularExpression( '/value="desc"\s+selected=[\'\"]selected[\'\"]/', $output );
		$this->assertMatchesRegularExpression( '/name="unused"\s+value="1"\s+checked=[\'\"]checked[\'\"]/', $output );
		$this->assertStringContainsString( '3 active conditions', $output );
		$this->assertStringContainsString( 'Keyword: WordPress', $output );
		$this->assertStringContainsString( 'Globally unused', $output );
		$this->assertStringContainsString( 'Published posts · Descending', $output );

		$matched = preg_match( '/<a class="button button-secondary tt-button tt-button--secondary" href="([^"]+)">/', $output, $matches );
		$this->assertSame( 1, $matched );
		$reset_url = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( 'page=taxonomy-tidy', $reset_url );
		$this->assertStringContainsString( 'taxonomy=post_tag', $reset_url );
		$this->assertStringNotContainsString( 's=', $reset_url );
		$this->assertStringNotContainsString( 'unused=', $reset_url );
		$this->assertStringNotContainsString( 'orderby=', $reset_url );
		$this->assertStringNotContainsString( 'order=', $reset_url );
		$this->assertStringNotContainsString( 'paged=', $reset_url );
	}

	/**
	 * The tag screen does not include matching category rows.
	 */
	public function test_category_and_tag_rows_are_rendered_in_separate_views(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Inventory Screen Category',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Inventory Screen Tag',
			)
		);

		$_GET = array(
			'taxonomy' => 'post_tag',
			's'        => 'Inventory Screen',
		);

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Inventory Screen Tag', $output );
		$this->assertStringNotContainsString( 'Inventory Screen Category', $output );
		$this->assertLessThan( strpos( $output, 'taxonomy-tidy-process-panel' ), strpos( $output, 'taxonomy-tidy-filter-panel' ) );
		$this->assertLessThan( strpos( $output, 'taxonomy-tidy-inventory-table' ), strpos( $output, 'taxonomy-tidy-process-panel' ) );
	}

	/**
	 * Stored term values are escaped in every rendered context.
	 */
	public function test_term_output_is_escaped(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Escaping fixture',
			)
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The isolated test intentionally simulates hostile stored data.
		$wpdb->update(
			$wpdb->terms,
			array(
				'name' => 'A & B <em>unsafe</em>',
				'slug' => 'quote-"-slug',
			),
			array( 'term_id' => $term_id )
		);
		clean_term_cache( $term_id, 'post_tag' );
		$_GET = array( 'taxonomy' => 'post_tag' );

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'A &amp; B &lt;em&gt;unsafe&lt;/em&gt;', $output );
		$this->assertStringNotContainsString( '<em>unsafe</em>', $output );
		$this->assertStringNotContainsString( 'value="quote-"-slug"', $output );
	}

	/**
	 * A user missing the required capabilities does not receive a menu entry.
	 */
	public function test_unauthorized_user_does_not_receive_menu(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$registered_pages_before = $GLOBALS['_registered_pages'] ?? array();

		$this->page->register_menu();

		$this->assertFalse( Access::current_user_can_access() );
		$this->assertSame(
			$registered_pages_before,
			$GLOBALS['_registered_pages'] ?? array()
		);
	}

	/**
	 * Direct rendering is rejected even when the menu registration is bypassed.
	 */
	public function test_unauthorized_user_cannot_render_page_directly(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'manage_categories' );
		wp_set_current_user( $user_id );
		$this->assertTrue( current_user_can( 'manage_categories' ) );
		$this->assertFalse( Access::current_user_can_access() );

		add_filter(
			'wp_die_handler',
			static function (): callable {
				return static function (): void {
					throw new RuntimeException( 'Access denied.' );
				};
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Access denied.' );
		$this->page->render();
	}
}
