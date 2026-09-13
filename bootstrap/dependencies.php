<?php
/**
 * Runtime dependency guard loaded before the Composer autoloader.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy;

/**
 * Returns whether the Composer runtime autoloader is available.
 *
 * @param string $plugin_directory Absolute plugin directory.
 */
function has_runtime_dependencies( string $plugin_directory ): bool {
	return is_readable( $plugin_directory . '/vendor/autoload.php' );
}

/**
 * Explains why the plugin remained inactive when dependencies are missing.
 */
function render_missing_dependencies_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html__(
				'Taxonomy Tidy could not start because vendor/autoload.php is missing.',
				'taxonomy-tidy'
			);
			?>
			<code>docker compose run --rm composer install</code>
		</p>
	</div>
	<?php
}
