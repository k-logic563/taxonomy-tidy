<?php
/**
 * Deterministic Playwright fixture for the isolated E2E WordPress project.
 *
 * This development-only file is excluded from distribution packages.
 *
 * @package TaxonomyTidy
 */

use TaxonomyTidy\Infrastructure\Database\Tables;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The E2E fixture must run through WP-CLI.' );
}

$expected_url = getenv( 'E2E_EXPECTED_URL' );
$actual_url   = untrailingslashit( home_url() );
$expected_url = is_string( $expected_url ) ? untrailingslashit( $expected_url ) : '';
$host         = (string) wp_parse_url( $actual_url, PHP_URL_HOST );

if (
	'local' !== wp_get_environment_type()
	|| ! in_array( $host, array( '127.0.0.1', 'localhost' ), true )
	|| '' === $expected_url
	|| $actual_url !== $expected_url
	|| 'Taxonomy Tidy E2E' !== get_bloginfo( 'name' )
) {
	WP_CLI::error( 'Refusing to change fixtures outside the verified E2E WordPress site.' );
}

if ( 'reset' !== (string) ( $args[0] ?? '' ) ) {
	WP_CLI::error( 'Expected the reset command.' );
}

global $wpdb;

$registry_key = 'taxonomy_tidy_e2e_fixture_v1';
$registry     = get_option( $registry_key, array() );
$registry     = is_array( $registry ) ? $registry : array();

foreach ( (array) ( $registry['posts'] ?? array() ) as $fixture_post_id ) {
	if ( null !== get_post( (int) $fixture_post_id ) ) {
		wp_delete_post( (int) $fixture_post_id, true );
	}
}

foreach ( array( 'category', 'post_tag' ) as $fixture_taxonomy ) {
	foreach ( (array) ( $registry['terms'][ $fixture_taxonomy ] ?? array() ) as $term_id ) {
		if ( get_term( (int) $term_id, $fixture_taxonomy ) instanceof WP_Term ) {
			wp_delete_term( (int) $term_id, $fixture_taxonomy );
		}
	}
}

$changes_table    = Tables::changes( $wpdb );
$items_table      = Tables::items( $wpdb );
$operations_table = Tables::operations( $wpdb );
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted plugin tables in an isolated E2E database.
$wpdb->query( "DELETE FROM {$changes_table}" );
$wpdb->query( "DELETE FROM {$items_table}" );
$wpdb->query( "DELETE FROM {$operations_table}" );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

$definitions = array(
	'category' => array(
		'cat_rename'       => array( 'E2E-CAT-RENAME', 'e2e-cat-rename' ),
		'cat_merge_source' => array( 'E2E-CAT-MERGE-SOURCE', 'e2e-cat-merge-source' ),
		'cat_merge_target' => array( 'E2E-CAT-MERGE-TARGET', 'e2e-cat-merge-target' ),
		'cat_unused'       => array( 'E2E-CAT-UNUSED', 'e2e-cat-unused' ),
		'cat_unrelated'    => array( 'E2E-CAT-UNRELATED', 'e2e-cat-unrelated' ),
	),
	'post_tag' => array(
		'tag_rename'       => array( 'E2E-TAG-RENAME', 'e2e-tag-rename' ),
		'tag_merge_source' => array( 'E2E-TAG-MERGE-SOURCE', 'e2e-tag-merge-source' ),
		'tag_merge_target' => array( 'E2E-TAG-MERGE-TARGET', 'e2e-tag-merge-target' ),
		'tag_unused'       => array( 'E2E-TAG-UNUSED', 'e2e-tag-unused' ),
		'tag_unrelated'    => array( 'E2E-TAG-UNRELATED', 'e2e-tag-unrelated' ),
	),
);

$fixture_terms = array(
	'category' => array(),
	'post_tag' => array(),
);
foreach ( $definitions as $fixture_taxonomy => $items ) {
	foreach ( $items as $key => $definition ) {
		$result = wp_insert_term(
			$definition[0],
			$fixture_taxonomy,
			array(
				'slug'        => $definition[1],
				'description' => 'taxonomy-tidy-e2e-fixture',
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Could not create an E2E fixture term.' );
		}
		$fixture_terms[ $fixture_taxonomy ][ $key ] = (int) $result['term_id'];
	}
}

$post_definitions = array(
	'pub_01'   => array( 'E2E-PUB-01', 'publish' ),
	'pub_02'   => array( 'E2E-PUB-02', 'publish' ),
	'draft_01' => array( 'E2E-DRAFT-01', 'draft' ),
);
$fixture_posts    = array();
foreach ( $post_definitions as $key => $definition ) {
	$fixture_post_id = wp_insert_post(
		array(
			'post_title'   => $definition[0],
			'post_name'    => strtolower( $definition[0] ),
			'post_content' => 'Taxonomy Tidy Playwright fixture.',
			'post_status'  => $definition[1],
			'post_type'    => 'post',
		),
		true
	);
	if ( is_wp_error( $fixture_post_id ) ) {
		WP_CLI::error( 'Could not create an E2E fixture post.' );
	}
	$fixture_posts[ $key ] = (int) $fixture_post_id;
}

$assignments = array(
	'pub_01'   => array(
		'category' => array( 'cat_rename', 'cat_merge_source', 'cat_unrelated' ),
		'post_tag' => array( 'tag_rename', 'tag_merge_source', 'tag_unrelated' ),
	),
	'pub_02'   => array(
		'category' => array( 'cat_merge_target', 'cat_unrelated' ),
		'post_tag' => array( 'tag_merge_target', 'tag_unrelated' ),
	),
	'draft_01' => array(
		'category' => array( 'cat_merge_source' ),
		'post_tag' => array( 'tag_merge_source' ),
	),
);

foreach ( $assignments as $post_key => $taxonomies ) {
	foreach ( $taxonomies as $fixture_taxonomy => $term_keys ) {
		$ids    = array_map( static fn( string $key ): int => $fixture_terms[ $fixture_taxonomy ][ $key ], $term_keys );
		$result = wp_set_object_terms( $fixture_posts[ $post_key ], $ids, $fixture_taxonomy, false );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Could not assign an E2E fixture term.' );
		}
	}
}

update_option(
	$registry_key,
	array(
		'marker' => 'taxonomy-tidy-e2e-v1',
		'terms'  => $fixture_terms,
		'posts'  => $fixture_posts,
	),
	false
);

WP_CLI::success( 'E2E fixture reset complete.' );
