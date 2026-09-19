<?php
/**
 * Administration-script regression checks.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use WP_UnitTestCase;

/** Keeps native destination selection synchronized with selected sources. */
final class AdminScriptTest extends WP_UnitTestCase {
	/** Every selected source is hidden and disabled in the destination select. */
	public function test_destination_options_are_re_evaluated_by_stable_id(): void {
		$script = $this->script();
		$this->assertStringContainsString( 'destination.selectedOptions[ 0 ]?.dataset.termKey', $script );
		$this->assertStringContainsString( 'currentSourceIds.has( option.dataset.termKey )', $script );
		$this->assertStringContainsString( 'option.disabled = excluded', $script );
		$this->assertStringContainsString( 'option.hidden = excluded', $script );
	}

	/** A destination newly selected as a source is cleared with the required notice. */
	public function test_selected_destination_is_cleared_after_source_change(): void {
		$script = $this->script();
		$this->assertStringContainsString( 'currentSourceIds.has( selectedId )', $script );
		$this->assertStringContainsString( "destination.value = ''", $script );
		$this->assertStringContainsString( 'destinationNotice.textContent = destinationGroup.dataset.cleared', $script );
		$this->assertStringNotContainsString( 'taxonomy_tidy_search_destinations', $script );
	}

	/** Returns the administration script source. */
	private function script(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local version-controlled test subject.
		$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' );
		$this->assertIsString( $script );
		return $script;
	}
}
