<?php
/**
 * Admin access policy.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

/**
 * Applies the capabilities required by the implementation plan.
 */
final class Access {
	/**
	 * Required capabilities for every plugin screen and stored operation request.
	 *
	 * @var list<string>
	 */
	private const REQUIRED_CAPABILITIES = array(
		'manage_categories',
		'edit_others_posts',
		'edit_published_posts',
	);

	/**
	 * Returns whether the current user has every required capability.
	 */
	public static function current_user_can_access(): bool {
		foreach ( self::REQUIRED_CAPABILITIES as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return false;
			}
		}

		return true;
	}
}
