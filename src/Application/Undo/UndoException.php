<?php
/**
 * Coded Undo failure.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Application\Undo;

use RuntimeException;

// phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found -- Property promotion stores the safe code for the UI boundary.
/** Internal exception whose code is translated at the UI boundary. */
final class UndoException extends RuntimeException {
	/**
	 * Creates an exception carrying a safe stable code.
	 *
	 * @param string $undo_code Stable Undo error code.
	 */
	public function __construct( private readonly string $undo_code ) {
		parent::__construct( $undo_code );
	}

	/** Returns the stable non-technical error code. */
	public function error_code(): string {
		return $this->undo_code;
	}
}
