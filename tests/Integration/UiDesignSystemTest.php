<?php
/**
 * Administration design-system regression checks.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use WP_UnitTestCase;

/** Keeps shared visual rules documented and isolated to the plugin UI. */
final class UiDesignSystemTest extends WP_UnitTestCase {
	/** Tokens and presentation components remain rooted in Term Steward. */
	public function test_styles_define_scoped_tokens_and_components(): void {
		$css = $this->read( 'assets/css/admin.css' );
		$this->assertStringContainsString( ".term-steward {\n\t--tt-color-text:", $css );
		$this->assertStringContainsString( '--tt-control-height: 36px;', $css );
		$this->assertStringContainsString( '.term-steward .tt-button', $css );
		$this->assertStringContainsString( '.term-steward .tt-control', $css );
		$this->assertStringContainsString( '.term-steward .term-steward-modal__close:focus-visible', $css );
		$this->assertStringContainsString( ".term-steward .term-steward-plan-notice {\n\tdisplay: flex;\n\talign-items: center;", $css );
		$this->assertStringContainsString( ".term-steward .term-steward-plan-notice > p {\n\tflex: 1 1 auto;\n\tmin-width: 0;\n\tmargin: 0;", $css );
		$this->assertStringNotContainsString( "\n.notice {", $css );
		$this->assertDoesNotMatchRegularExpression( '/(?:^|})\s*(?:button|input|select|textarea|p|h[1-6])\s*\{/m', $css );
	}

	/** The checked-in design rules describe the implemented scope and states. */
	public function test_design_system_document_covers_required_contract(): void {
		$document = $this->read( 'docs/UI_DESIGN_SYSTEM.md' );
		foreach ( array( '## 1. 適用範囲', '## 2. デザイントークン', '## 3. 余白', '## 4. タイポグラフィ', '## 5. ボタン', '## 6. フォーム', '## 7. パネル、セクション、メッセージ', '## 8. テーブルとページネーション', '## 9. モーダル' ) as $heading ) {
			$this->assertStringContainsString( $heading, $document );
		}
		$this->assertStringContainsString( 'JavaScriptは原則として`term-steward-*`', $document );
		$this->assertStringContainsString( '`:focus-visible`', $document );
	}

	/**
	 * Reads one version-controlled test subject.
	 *
	 * @param string $path Repository-relative path.
	 */
	private function read( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local version-controlled test subjects.
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
		$this->assertIsString( $contents );
		return $contents;
	}
}
