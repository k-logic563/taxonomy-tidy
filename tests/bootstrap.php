<?php
/**
 * PHPUnit bootstrap for the WordPress integration test suite.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

$tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! is_string( $tests_dir ) || '' === $tests_dir ) {
	$tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI error before WordPress loads; not HTML.
	throw new RuntimeException( "WordPress test library not found at {$tests_dir}." );
}

require_once $tests_dir . '/includes/functions.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define(
		'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
		dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills'
	);
}

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/taxonomy-tidy.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
