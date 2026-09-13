<?php
/**
 * Plugin lifecycle hooks.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy;

/**
 * Handles activation and deactivation without creating persistent data yet.
 */
final class Lifecycle {
	/**
	 * Runs when the plugin is activated.
	 */
	public static function activate(): void {
		// Phase 1 intentionally creates no options or database tables.
	}

	/**
	 * Runs when the plugin is deactivated.
	 */
	public static function deactivate(): void {
		// Phase 1 has no scheduled work or temporary state to remove.
	}
}
