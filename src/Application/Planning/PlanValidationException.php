<?php
/**
 * Plan validation exception.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Application\Planning;

use RuntimeException;

/**
 * Carries stable validation codes without mixing them with rendered messages.
 */
final class PlanValidationException extends RuntimeException {
	/**
	 * Validation codes.
	 *
	 * @var list<string>
	 */
	private array $codes;

	/**
	 * Creates an exception for one or more validation failures.
	 *
	 * @param array $codes Stable validation codes.
	 */
	public function __construct( array $codes ) {
		$this->codes = array_values( array_unique( $codes ) );
		parent::__construct( implode( ', ', $this->codes ) );
	}

	/**
	 * Returns stable validation codes for UI translation and tests.
	 *
	 * @return list<string>
	 */
	public function codes(): array {
		return $this->codes;
	}
}
