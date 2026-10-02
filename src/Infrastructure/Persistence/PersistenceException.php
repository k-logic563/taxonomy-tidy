<?php
/**
 * Persistence exception.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Infrastructure\Persistence;

use RuntimeException;

/**
 * Reports an unexpected persistence failure to the application layer.
 */
final class PersistenceException extends RuntimeException {
}
