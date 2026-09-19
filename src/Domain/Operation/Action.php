<?php
/**
 * Supported operation actions.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Domain\Operation;

/**
 * Limits persisted items to the three MVP mutation types.
 */
enum Action: string {
	case RENAME = 'rename';
	case MERGE  = 'merge';
	case DELETE = 'delete';
	case UNDO   = 'undo';
}
