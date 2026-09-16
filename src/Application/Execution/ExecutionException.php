<?php
/**
 * Safe execution failure.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Application\Execution;

use RuntimeException;

/**
 * Carries a stable code rather than an internal exception message.
 */
final class ExecutionException extends RuntimeException {
	/**
	 * Returns the stable error code stored as the exception message.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->getMessage();
	}
}
