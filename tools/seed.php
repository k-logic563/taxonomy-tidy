<?php
/**
 * WP-CLI entry point for local-development seed data.
 *
 * This file is excluded from production plugin packages.
 *
 * @package TaxonomyTidy
 */

use TaxonomyTidy\Development\SeedManager;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The seed script must be executed through WP-CLI.' );
}

require_once __DIR__ . '/seed/SeedManager.php';

$command = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';

try {
	$result = ( new SeedManager() )->execute( $command, wp_get_environment_type() );
	WP_CLI::success(
		sprintf(
			'Seed %s complete: %d posts/pages, %d categories, %d tags.',
			$result['mode'],
			$result['posts'],
			$result['categories'],
			$result['tags']
		)
	);
} catch ( Throwable $exception ) {
	WP_CLI::error( $exception->getMessage() );
}
