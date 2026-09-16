<?php
/**
 * Stable planning error identifiers.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Planning;

/**
 * Keeps internal validation decisions independent from translated UI text.
 */
final class PlanErrorCode {
	public const SELECTION_REQUIRED      = 'selection_required';
	public const SELECTION_INVALID       = 'selection_invalid';
	public const SINGLE_TERM_REQUIRED    = 'single_term_required';
	public const OPERATION_REQUIRED      = 'operation_required';
	public const NEW_NAME_REQUIRED       = 'new_name_required';
	public const INVALID_NAME            = 'invalid_name';
	public const NAME_UNCHANGED          = 'name_unchanged';
	public const NAME_CONFLICT           = 'name_conflict';
	public const INVALID_SLUG            = 'invalid_slug';
	public const SLUG_CONFLICT           = 'slug_conflict';
	public const DESTINATION_REQUIRED    = 'destination_required';
	public const SAME_SOURCE_DESTINATION = 'same_source_destination';
	public const TAXONOMY_MISMATCH       = 'taxonomy_mismatch';
	public const DESCENDANT_DESTINATION  = 'descendant_destination';
	public const CIRCULAR_HIERARCHY      = 'circular_hierarchy';
	public const TERM_IN_USE             = 'term_in_use';
	public const DEFAULT_CATEGORY        = 'default_category';
	public const STALE_PREVIEW           = 'stale_preview';
	public const PLAN_CONFLICT           = 'plan_conflict';
	public const PLAN_INVALID            = 'plan_invalid';
	public const PERMISSION_DENIED       = 'permission_denied';
	public const INVALID_NONCE           = 'invalid_nonce';
	public const UNKNOWN_ERROR           = 'unknown_error';

	/**
	 * Static constants only.
	 */
	private function __construct() {
	}
}
