<?php
/**
 * Stable Undo error codes.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Undo;

/** Keeps technical failures out of the administration UI. */
final class UndoErrorCode {
	public const INVALID_OPERATION = 'undo_invalid_operation';
	public const NOT_AVAILABLE     = 'undo_not_available';
	public const STALE_PREVIEW     = 'undo_stale_preview';
	public const LOCKED            = 'undo_locked';
	public const CONFLICT          = 'undo_conflict';
	public const UPDATE_FAILED     = 'undo_update_failed';
	public const JOURNAL_FAILED    = 'undo_journal_failed';
}
