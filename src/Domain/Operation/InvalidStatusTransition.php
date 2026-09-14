<?php
/**
 * Invalid operation transition exception.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Domain\Operation;

use LogicException;

/**
 * Raised before an invalid operation state can be persisted.
 */
final class InvalidStatusTransition extends LogicException {
}
