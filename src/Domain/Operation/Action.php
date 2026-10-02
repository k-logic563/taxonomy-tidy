<?php
/**
 * Supported operation actions.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Domain\Operation;

/**
 * Limits persisted items to the three MVP mutation types.
 */
enum Action: string {
	case RENAME = 'rename';
	case MERGE  = 'merge';
	case DELETE = 'delete';
	case UNDO   = 'undo';
}
