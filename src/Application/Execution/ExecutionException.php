<?php
/**
 * Safe execution failure.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Application\Execution;

use RuntimeException;

/**
 * Carries a stable code rather than an internal exception message.
 */
final class ExecutionException extends RuntimeException {
	/**
	 * Creates a coded failure with optional user-safe target context.
	 *
	 * @param string      $code        Stable error code.
	 * @param string|null $target_name Stored target label safe for escaped display.
	 * @param string|null $reason      Stable target-specific reason code.
	 */
	public function __construct(
		string $code,
		private readonly ?string $target_name = null,
		private readonly ?string $reason = null
	) {
		parent::__construct( $code );
	}

	/**
	 * Returns the stable error code stored as the exception message.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->getMessage();
	}

	/** Returns an optional target label that contains no technical details. */
	public function target_name(): ?string {
		return $this->target_name;
	}

	/** Returns an optional stable reason code. */
	public function reason(): ?string {
		return $this->reason;
	}
}
