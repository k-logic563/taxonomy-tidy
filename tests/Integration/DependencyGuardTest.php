<?php
/**
 * Runtime dependency guard integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use WP_UnitTestCase;

use function TaxonomyTidy\has_runtime_dependencies;
use function TaxonomyTidy\render_missing_dependencies_notice;

/**
 * Verifies that a missing Composer autoloader fails safely and clearly.
 */
final class DependencyGuardTest extends WP_UnitTestCase {
	/**
	 * The active development installation has a readable autoloader.
	 */
	public function test_runtime_autoloader_is_available(): void {
		$this->assertTrue( has_runtime_dependencies( dirname( __DIR__, 2 ) ) );
	}

	/**
	 * A missing autoloader is detected and its notice explains the remedy.
	 */
	public function test_missing_autoloader_has_an_actionable_error_notice(): void {
		$missing_directory = dirname( __DIR__ ) . '/Fixtures/MissingPlugin';

		$this->assertFalse( has_runtime_dependencies( $missing_directory ) );

		ob_start();
		render_missing_dependencies_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'vendor/autoload.php is missing', $output );
		$this->assertStringContainsString( 'docker compose run --rm composer install', $output );
	}
}
