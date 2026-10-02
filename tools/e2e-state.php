<?php
/**
 * Read-only state snapshot for Playwright assertions.
 *
 * This development-only file is excluded from distribution packages.
 *
 * @package TermSteward
 */

use TermSteward\Infrastructure\Database\Tables;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The E2E state helper must run through WP-CLI.' );
}

$expected_url = getenv( 'E2E_EXPECTED_URL' );
$actual_url   = untrailingslashit( home_url() );
$expected_url = is_string( $expected_url ) ? untrailingslashit( $expected_url ) : '';
$host         = (string) wp_parse_url( $actual_url, PHP_URL_HOST );
if ( 'local' !== wp_get_environment_type() || ! in_array( $host, array( '127.0.0.1', 'localhost' ), true ) || '' === $expected_url || $actual_url !== $expected_url || 'Term Steward E2E' !== get_bloginfo( 'name' ) ) {
	WP_CLI::error( 'Refusing to inspect an unverified WordPress site.' );
}
if ( 'snapshot' !== (string) ( $args[0] ?? '' ) ) {
	WP_CLI::error( 'Expected the snapshot command.' );
}

global $wpdb;
$registry = get_option( 'term_steward_e2e_fixture_v1', array() );
if ( ! is_array( $registry ) || 'term-steward-e2e-v1' !== ( $registry['marker'] ?? null ) ) {
	WP_CLI::error( 'The E2E fixture registry is missing or invalid.' );
}

$snapshot = array(
	'terms'       => array(),
	'posts'       => array(),
	'persistence' => array(),
);
foreach ( (array) $registry['terms'] as $fixture_taxonomy => $fixture_terms ) {
	foreach ( (array) $fixture_terms as $key => $term_id ) {
		$fixture_term              = get_term( (int) $term_id, (string) $fixture_taxonomy );
		$snapshot['terms'][ $key ] = $fixture_term instanceof WP_Term
			? array(
				'exists'   => true,
				'id'       => $fixture_term->term_id,
				'name'     => $fixture_term->name,
				'slug'     => $fixture_term->slug,
				'taxonomy' => $fixture_term->taxonomy,
			)
			: array(
				'exists'   => false,
				'id'       => (int) $term_id,
				'taxonomy' => (string) $fixture_taxonomy,
			);
	}
}

foreach ( (array) $registry['posts'] as $key => $fixture_post_id ) {
	$fixture_post = get_post( (int) $fixture_post_id );
	if ( ! $fixture_post instanceof WP_Post ) {
		$snapshot['posts'][ $key ] = array( 'exists' => false );
		continue;
	}
	$relationships = array();
	foreach ( array( 'category', 'post_tag' ) as $fixture_taxonomy ) {
		$assigned                           = wp_get_object_terms( $fixture_post->ID, $fixture_taxonomy, array( 'fields' => 'names' ) );
		$relationships[ $fixture_taxonomy ] = is_wp_error( $assigned ) ? array() : array_values( $assigned );
		sort( $relationships[ $fixture_taxonomy ] );
	}
	$snapshot['posts'][ $key ] = array(
		'exists'        => true,
		'id'            => $fixture_post->ID,
		'title'         => $fixture_post->post_title,
		'status'        => $fixture_post->post_status,
		'relationships' => $relationships,
	);
}

$operations = Tables::operations( $wpdb );
$items      = Tables::items( $wpdb );
$changes    = Tables::changes( $wpdb );
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table names and read-only E2E aggregate queries.
$snapshot['persistence']['operations']         = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS count FROM {$operations} GROUP BY status", ARRAY_A );
$snapshot['persistence']['items']              = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items}" );
$snapshot['persistence']['journals']           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$changes}" );
$snapshot['persistence']['duplicate_items']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT operation_id, item_key FROM {$items} GROUP BY operation_id, item_key HAVING COUNT(*) > 1) duplicates" );
$snapshot['persistence']['duplicate_journals'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT operation_id, change_key FROM {$changes} GROUP BY operation_id, change_key HAVING COUNT(*) > 1) duplicates" );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

WP_CLI::line( wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
