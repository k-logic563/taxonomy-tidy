<?php
/**
 * Persistence table names.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Infrastructure\Database;

use wpdb;

/**
 * Resolves site-prefixed table names in one place.
 */
final class Tables {
	/**
	 * Returns the operation table name.
	 *
	 * @param \wpdb $database WordPress database connection supplying the site prefix.
	 */
	public static function operations( wpdb $database ): string {
		return $database->prefix . 'taxonomy_tidy_operations';
	}

	/**
	 * Returns the operation item table name.
	 *
	 * @param \wpdb $database WordPress database connection supplying the site prefix.
	 */
	public static function items( wpdb $database ): string {
		return $database->prefix . 'taxonomy_tidy_operation_items';
	}

	/**
	 * Returns the change journal table name.
	 *
	 * @param \wpdb $database WordPress database connection supplying the site prefix.
	 */
	public static function changes( wpdb $database ): string {
		return $database->prefix . 'taxonomy_tidy_changes';
	}
}
