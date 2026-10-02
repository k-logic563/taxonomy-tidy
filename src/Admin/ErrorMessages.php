<?php
/**
 * Localized administration error messages.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Admin;

use TermSteward\Application\Planning\PlanErrorCode;
use TermSteward\Application\Execution\ExecutionErrorCode;

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
			PlanErrorCode::SELECTION_REQUIRED => __( 'Select a category or tag to process.', 'term-steward' ),
			PlanErrorCode::SELECTION_INVALID => __( 'The selected category or tag could not be verified. Select it again.', 'term-steward' ),
			PlanErrorCode::SINGLE_TERM_REQUIRED => __( 'Select exactly one target when renaming.', 'term-steward' ),
			PlanErrorCode::OPERATION_REQUIRED => __( 'Select an action method.', 'term-steward' ),
			PlanErrorCode::NEW_NAME_REQUIRED => __( 'Enter a new name.', 'term-steward' ),
			PlanErrorCode::INVALID_NAME => __( 'The new name contains invalid characters.', 'term-steward' ),
			PlanErrorCode::NAME_UNCHANGED => __( 'Enter a name different from the current name.', 'term-steward' ),
			PlanErrorCode::NAME_CONFLICT => __( 'This name is already in use.', 'term-steward' ),
			PlanErrorCode::INVALID_SLUG => __( 'The slug format is invalid.', 'term-steward' ),
			PlanErrorCode::SLUG_CONFLICT => __( 'This slug is already in use.', 'term-steward' ),
			PlanErrorCode::DESTINATION_REQUIRED => __( 'Select a merge destination.', 'term-steward' ),
			PlanErrorCode::SAME_SOURCE_DESTINATION => __( 'The merge destination cannot be the same as its source.', 'term-steward' ),
			PlanErrorCode::TAXONOMY_MISMATCH => __( 'Categories and tags cannot be merged with each other.', 'term-steward' ),
			PlanErrorCode::DESCENDANT_DESTINATION => __( 'A category cannot be merged into one of its descendants.', 'term-steward' ),
			PlanErrorCode::CIRCULAR_HIERARCHY => __( 'The category hierarchy would become circular, so the merge cannot continue.', 'term-steward' ),
			PlanErrorCode::TERM_IN_USE => __( 'A category or tag that is in use cannot be deleted.', 'term-steward' ),
			PlanErrorCode::DEFAULT_CATEGORY => __( 'The default category cannot be deleted.', 'term-steward' ),
			PlanErrorCode::STALE_PREVIEW => __( 'The taxonomy state has changed. Review the plan again.', 'term-steward' ),
			PlanErrorCode::PLAN_CONFLICT => __( 'The operation plan contains conflicting actions.', 'term-steward' ),
			PlanErrorCode::PLAN_INVALID => __( 'The saved operation plan is no longer available. Review it again.', 'term-steward' ),
			PlanErrorCode::PERMISSION_DENIED => __( 'You do not have permission to perform this action.', 'term-steward' ),
			PlanErrorCode::INVALID_NONCE => __( 'This action has expired. Reload the page and try again.', 'term-steward' ),
			ExecutionErrorCode::INVALID_OPERATION => __( 'This operation cannot be executed from its current state.', 'term-steward' ),
			ExecutionErrorCode::STALE_PREVIEW => __( 'The preview is out of date. Review the changes again before executing.', 'term-steward' ),
			ExecutionErrorCode::NO_STARTABLE_ITEMS => __( 'None of the planned items can be started. Create the plan again and review a new preview.', 'term-steward' ),
			ExecutionErrorCode::LOCKED => __( 'Another taxonomy operation is currently running. Try again after it finishes.', 'term-steward' ),
			ExecutionErrorCode::TARGET_CHANGED => __( 'A target changed after preview. The affected item was not changed.', 'term-steward' ),
			ExecutionErrorCode::UPDATE_FAILED => __( 'WordPress could not apply one of the planned changes.', 'term-steward' ),
			ExecutionErrorCode::JOURNAL_FAILED => __( 'The change result could not be recorded safely.', 'term-steward' ),
			default => __( 'The operation could not continue. Please try again.', 'term-steward' ),
		};
	}

	/**
	 * Static service only.
	 */
	private function __construct() {
	}
}
