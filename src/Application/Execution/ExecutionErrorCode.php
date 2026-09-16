<?php
/**
 * Stable execution error identifiers.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Execution;

/** Execution errors safe to map to translated UI messages. */
final class ExecutionErrorCode {
	public const INVALID_OPERATION = 'execution_invalid_operation';
	public const STALE_PREVIEW     = 'execution_stale_preview';
	public const LOCKED            = 'execution_locked';
	public const TARGET_CHANGED    = 'execution_target_changed';
	public const UPDATE_FAILED     = 'execution_update_failed';
	public const JOURNAL_FAILED    = 'execution_journal_failed';

	/** Static constants only. */
	private function __construct() {
	}
}
