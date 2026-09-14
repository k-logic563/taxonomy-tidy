<?php
/**
 * Operation state transition tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Domain\Operation\InvalidStatusTransition;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\StatusTransitions;
use WP_UnitTestCase;

/**
 * Verifies the closed MVP operation state graph.
 */
final class StatusTransitionsTest extends WP_UnitTestCase {
	/**
	 * Every required transition is allowed.
	 */
	public function test_required_transitions_are_allowed(): void {
		$transitions = array(
			array( Status::DRAFT, Status::PREVIEWED ),
			array( Status::PREVIEWED, Status::RUNNING ),
			array( Status::RUNNING, Status::COMPLETED ),
			array( Status::RUNNING, Status::PARTIAL_FAILED ),
			array( Status::RUNNING, Status::FAILED ),
			array( Status::COMPLETED, Status::UNDO_PREVIEWED ),
			array( Status::UNDO_PREVIEWED, Status::UNDOING ),
			array( Status::UNDOING, Status::UNDONE ),
			array( Status::UNDOING, Status::UNDO_PARTIAL_FAILED ),
		);

		foreach ( $transitions as list( $from, $to ) ) {
			$this->assertTrue( StatusTransitions::allows( $from, $to ) );
			StatusTransitions::assert_allowed( $from, $to );
		}
	}

	/**
	 * An unspecified transition is rejected.
	 */
	public function test_invalid_transition_is_rejected(): void {
		$this->expectException( InvalidStatusTransition::class );
		StatusTransitions::assert_allowed( Status::DRAFT, Status::COMPLETED );
	}
}
