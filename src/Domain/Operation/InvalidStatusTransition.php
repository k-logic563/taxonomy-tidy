<?php
/**
 * Invalid operation transition exception.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Domain\Operation;

use LogicException;

/**
 * Raised before an invalid operation state can be persisted.
 */
final class InvalidStatusTransition extends LogicException {
}
