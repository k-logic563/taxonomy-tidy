<?php
/**
 * Localized administration error messages.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

use TaxonomyTidy\Application\Planning\PlanErrorCode;
use TaxonomyTidy\Application\Execution\ExecutionErrorCode;

/**
 * Converts stable internal codes to text in the current administration locale.
 */
final class ErrorMessages {
	/**
	 * Returns a translated safe message for one error code.
	 *
	 * @param string $code Stable internal error code.
	 * @return string
	 */
	public static function label( string $code ): string {
		return match ( $code ) {
			PlanErrorCode::SELECTION_REQUIRED => __( 'Select a category or tag to process.', 'taxonomy-tidy' ),
			PlanErrorCode::SELECTION_INVALID => __( 'The selected category or tag could not be verified. Select it again.', 'taxonomy-tidy' ),
			PlanErrorCode::SINGLE_TERM_REQUIRED => __( 'Select exactly one target when renaming.', 'taxonomy-tidy' ),
			PlanErrorCode::OPERATION_REQUIRED => __( 'Select an action method.', 'taxonomy-tidy' ),
			PlanErrorCode::NEW_NAME_REQUIRED => __( 'Enter a new name.', 'taxonomy-tidy' ),
			PlanErrorCode::INVALID_NAME => __( 'The new name contains invalid characters.', 'taxonomy-tidy' ),
			PlanErrorCode::NAME_UNCHANGED => __( 'Enter a name different from the current name.', 'taxonomy-tidy' ),
			PlanErrorCode::NAME_CONFLICT => __( 'This name is already in use.', 'taxonomy-tidy' ),
			PlanErrorCode::INVALID_SLUG => __( 'The slug format is invalid.', 'taxonomy-tidy' ),
			PlanErrorCode::SLUG_CONFLICT => __( 'This slug is already in use.', 'taxonomy-tidy' ),
			PlanErrorCode::DESTINATION_REQUIRED => __( 'Select a merge destination.', 'taxonomy-tidy' ),
			PlanErrorCode::SAME_SOURCE_DESTINATION => __( 'The merge destination cannot be the same as its source.', 'taxonomy-tidy' ),
			PlanErrorCode::TAXONOMY_MISMATCH => __( 'Categories and tags cannot be merged with each other.', 'taxonomy-tidy' ),
			PlanErrorCode::DESCENDANT_DESTINATION => __( 'A category cannot be merged into one of its descendants.', 'taxonomy-tidy' ),
			PlanErrorCode::CIRCULAR_HIERARCHY => __( 'The category hierarchy would become circular, so the merge cannot continue.', 'taxonomy-tidy' ),
			PlanErrorCode::TERM_IN_USE => __( 'A category or tag that is in use cannot be deleted.', 'taxonomy-tidy' ),
			PlanErrorCode::DEFAULT_CATEGORY => __( 'The default category cannot be deleted.', 'taxonomy-tidy' ),
			PlanErrorCode::STALE_PREVIEW => __( 'The taxonomy state has changed. Review the plan again.', 'taxonomy-tidy' ),
			PlanErrorCode::PLAN_CONFLICT => __( 'The operation plan contains conflicting actions.', 'taxonomy-tidy' ),
			PlanErrorCode::PLAN_INVALID => __( 'The saved operation plan is no longer available. Review it again.', 'taxonomy-tidy' ),
			PlanErrorCode::PERMISSION_DENIED => __( 'You do not have permission to perform this action.', 'taxonomy-tidy' ),
			PlanErrorCode::INVALID_NONCE => __( 'This action has expired. Reload the page and try again.', 'taxonomy-tidy' ),
			ExecutionErrorCode::INVALID_OPERATION => __( 'This operation cannot be executed from its current state.', 'taxonomy-tidy' ),
			ExecutionErrorCode::STALE_PREVIEW => __( 'The preview is out of date. Review the changes again before executing.', 'taxonomy-tidy' ),
			ExecutionErrorCode::LOCKED => __( 'Another taxonomy operation is currently running. Try again after it finishes.', 'taxonomy-tidy' ),
			ExecutionErrorCode::TARGET_CHANGED => __( 'A target changed after preview. The affected item was not changed.', 'taxonomy-tidy' ),
			ExecutionErrorCode::UPDATE_FAILED => __( 'WordPress could not apply one of the planned changes.', 'taxonomy-tidy' ),
			ExecutionErrorCode::JOURNAL_FAILED => __( 'The change result could not be recorded safely.', 'taxonomy-tidy' ),
			default => __( 'The operation could not continue. Please try again.', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Static service only.
	 */
	private function __construct() {
	}
}
