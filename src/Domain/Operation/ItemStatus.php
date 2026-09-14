<?php
/**
 * Operation item statuses.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Domain\Operation;

/**
 * Item states kept intentionally small for retry-safe batches.
 */
enum ItemStatus: string {
	case PENDING   = 'pending';
	case COMPLETED = 'completed';
	case FAILED    = 'failed';
}
