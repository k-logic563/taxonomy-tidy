<?php
/**
 * Plugin lifecycle hooks.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward;

use TermSteward\Infrastructure\Database\Schema;

/**
 * Handles activation and deactivation without creating persistent data yet.
 */
final class Lifecycle {
	/**
	 * Runs when the plugin is activated.
	 */
	public static function activate(): void {
		Schema::install();
	}

	/**
	 * Runs when the plugin is deactivated.
	 */
	public static function deactivate(): void {
		// Phase 1 has no scheduled work or temporary state to remove.
	}
}
