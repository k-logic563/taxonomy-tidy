<?php
/**
 * Operation statuses.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Domain\Operation;

/**
 * Explicit persisted states required by the MVP lifecycle.
 */
enum Status: string {
	case DRAFT               = 'draft';
	case PREVIEWED           = 'previewed';
	case RUNNING             = 'running';
	case COMPLETED           = 'completed';
	case PARTIAL_FAILED      = 'partial_failed';
	case FAILED              = 'failed';
	case UNDO_PREVIEWED      = 'undo_previewed';
	case UNDOING             = 'undoing';
	case UNDONE              = 'undone';
	case UNDO_PARTIAL_FAILED = 'undo_partial_failed';
}
