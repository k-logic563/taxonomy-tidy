<?php
/**
 * Plugin bootstrap integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Infrastructure\Database\Schema;
use TaxonomyTidy\Lifecycle;
use TaxonomyTidy\Plugin;
use WP_UnitTestCase;

use function TaxonomyTidy\load_translations;

/**
 * Verifies installation metadata and bootstrap hooks.
 */
final class PluginBootstrapTest extends WP_UnitTestCase {
	/**
	 * The main plugin file loads and declares the expected metadata.
	 */
	public function test_plugin_bootstraps_with_valid_headers(): void {
		$headers = get_file_data(
			TAXONOMY_TIDY_PLUGIN_FILE,
			array(
				'name'        => 'Plugin Name',
				'version'     => 'Version',
				'requiresWP'  => 'Requires at least',
				'requiresPHP' => 'Requires PHP',
				'textDomain'  => 'Text Domain',
				'domainPath'  => 'Domain Path',
			)
		);

		$this->assertSame( 'Taxonomy Tidy', $headers['name'] );
		$this->assertSame( '0.1.0', $headers['version'] );
		$this->assertSame( '6.6', $headers['requiresWP'] );
		$this->assertSame( '8.2', $headers['requiresPHP'] );
		$this->assertSame( 'taxonomy-tidy', $headers['textDomain'] );
		$this->assertSame( '/languages', $headers['domainPath'] );
		$this->assertSame(
			10,
			has_action( 'admin_menu', array( Plugin::instance()->admin_page(), 'register_menu' ) )
		);
		$this->assertSame(
			10,
			has_action( 'admin_enqueue_scripts', array( Plugin::instance()->admin_page(), 'enqueue_assets' ) )
		);
		$this->assertFalse( has_action( 'wp_ajax_taxonomy_tidy_search_destinations' ) );
		$this->assertSame( 10, has_action( 'init', 'TaxonomyTidy\\load_translations' ) );
	}

	/**
	 * Admin styles and scripts are limited to the Taxonomy Tidy Tools screen.
	 */
	public function test_admin_styles_are_enqueued_only_for_plugin_screen(): void {
		$page = Plugin::instance()->admin_page();

		wp_dequeue_style( 'taxonomy-tidy-admin' );
		wp_dequeue_script( 'taxonomy-tidy-admin' );
		$page->enqueue_assets( 'tools_page_other-plugin' );
		$this->assertFalse( wp_style_is( 'taxonomy-tidy-admin', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'taxonomy-tidy-admin', 'enqueued' ) );

		$page->enqueue_assets( 'tools_page_taxonomy-tidy' );
		$this->assertTrue( wp_style_is( 'taxonomy-tidy-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'taxonomy-tidy-admin', 'enqueued' ) );
		wp_dequeue_style( 'taxonomy-tidy-admin' );
		wp_dequeue_script( 'taxonomy-tidy-admin' );
	}

	/**
	 * Lifecycle callbacks install the current schema without failing.
	 */
	public function test_lifecycle_callbacks_install_schema(): void {
		Lifecycle::activate();
		Lifecycle::deactivate();

		$this->assertSame( Schema::VERSION, Schema::stored_version() );
	}
}
