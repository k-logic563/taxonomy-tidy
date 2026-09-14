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
		$_GET               = array();
	}

	/**
	 * Resets the current user after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_GET = $this->original_get;
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
		$this->assertStringContainsString( '<details class="taxonomy-tidy-panel taxonomy-tidy-filter-panel" open>', $output );
		$this->assertStringContainsString( '<summary class="taxonomy-tidy-panel__summary">', $output );
		$this->assertStringContainsString( 'No active conditions', $output );
		$this->assertStringContainsString( '<label for="taxonomy-tidy-search">Keyword</label>', $output );
		$this->assertStringContainsString( 'aria-describedby="taxonomy-tidy-search-description"', $output );
		$this->assertStringContainsString( '<label for="taxonomy-tidy-orderby">Sort by</label>', $output );
		$this->assertStringContainsString( '<label for="taxonomy-tidy-order">Direction</label>', $output );
		$this->assertStringContainsString( 'Apply conditions', $output );
		$this->assertStringContainsString( 'Reset conditions', $output );
		$this->assertSame( 1, substr_count( $output, '<form ' ) );
		$this->assertStringContainsString( 'Published posts', $output );
		$this->assertStringContainsString( 'Total relationships', $output );
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

		$matched = preg_match( '/<a class="button button-secondary" href="([^"]+)">/', $output, $matches );
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
