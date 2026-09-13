<?php
/**
 * Plugin bootstrap integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Lifecycle;
use TaxonomyTidy\Plugin;
use WP_UnitTestCase;

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
			)
		);

		$this->assertSame( 'Taxonomy Tidy', $headers['name'] );
		$this->assertSame( '0.1.0', $headers['version'] );
		$this->assertSame( '6.6', $headers['requiresWP'] );
		$this->assertSame( '8.2', $headers['requiresPHP'] );
		$this->assertSame( 'taxonomy-tidy', $headers['textDomain'] );
		$this->assertSame(
			10,
			has_action( 'admin_menu', array( Plugin::instance()->admin_page(), 'register_menu' ) )
		);
	}

	/**
	 * Lifecycle callbacks remain harmless while Phase 1 has no persistence.
	 */
	public function test_lifecycle_callbacks_execute_without_side_effects(): void {
		Lifecycle::activate();
		Lifecycle::deactivate();

		$this->assertTrue( true );
	}
}
