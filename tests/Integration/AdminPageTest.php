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
		$this->assertStringContainsString( 'Published posts', $output );
		$this->assertStringContainsString( 'Total relationships', $output );
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
