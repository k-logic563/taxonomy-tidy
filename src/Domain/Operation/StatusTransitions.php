<?php
/**
 * Operation state transition policy.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Domain\Operation;

/**
 * Defines only the state transitions required by the MVP plan.
 */
final class StatusTransitions {
	/**
	 * Allowed next states keyed by the current state.
	 *
	 * @var array<string, list<Status>>
	 */
	private const ALLOWED = array(
		'draft'          => array( Status::PREVIEWED ),
		'previewed'      => array( Status::RUNNING ),
		'running'        => array(
			Status::COMPLETED,
			Status::PARTIAL_FAILED,
			Status::FAILED,
		),
		'completed'      => array( Status::UNDO_PREVIEWED ),
		'undo_previewed' => array( Status::UNDOING ),
		'undoing'        => array(
			Status::UNDONE,
			Status::UNDO_PARTIAL_FAILED,
		),
	);

	/**
	 * Returns whether a transition is explicitly allowed.
	 *
	 * @param Status $from Current operation status.
	 * @param Status $to   Requested operation status.
	 */
	public static function allows( Status $from, Status $to ): bool {
		return in_array( $to, self::ALLOWED[ $from->value ] ?? array(), true );
	}

	/**
	 * Rejects an invalid transition.
	 *
	 * @param Status $from Current operation status.
	 * @param Status $to   Requested operation status.
	 * @throws InvalidStatusTransition When the transition is not allowed.
	 */
	public static function assert_allowed( Status $from, Status $to ): void {
		if ( self::allows( $from, $to ) ) {
			return;
		}

		$message = sprintf( 'Operation status cannot change from %s to %s.', $from->value, $to->value );

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception text is not HTML output.
		throw new InvalidStatusTransition( $message );
	}
}
