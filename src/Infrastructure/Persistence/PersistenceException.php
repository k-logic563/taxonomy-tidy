<?php
/**
 * Persistence exception.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Infrastructure\Persistence;

use RuntimeException;

/**
 * Reports an unexpected persistence failure to the application layer.
 */
final class PersistenceException extends RuntimeException {
}
