<?php
/**
 * Plugin Name:       Taxonomy Tidy
 * Description:       Safely organize WordPress categories and tags in bulk.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Taxonomy Tidy Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taxonomy-tidy
 * Domain Path:       /languages
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TAXONOMY_TIDY_VERSION', '0.1.0' );
define( 'TAXONOMY_TIDY_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/bootstrap/dependencies.php';

add_action( 'init', 'TaxonomyTidy\\load_translations' );

if ( ! TaxonomyTidy\has_runtime_dependencies( __DIR__ ) ) {
	add_action( 'admin_notices', 'TaxonomyTidy\\render_missing_dependencies_notice' );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(
	TAXONOMY_TIDY_PLUGIN_FILE,
	array( TaxonomyTidy\Lifecycle::class, 'activate' )
);

register_deactivation_hook(
	TAXONOMY_TIDY_PLUGIN_FILE,
	array( TaxonomyTidy\Lifecycle::class, 'deactivate' )
);

TaxonomyTidy\Plugin::instance()->register();
