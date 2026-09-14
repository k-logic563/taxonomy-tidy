<?php
/**
 * Plugin translation integration tests.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Tests\Integration;

use TaxonomyTidy\Admin\Page;
use TaxonomyTidy\Plugin;
use WP_UnitTestCase;

use function TaxonomyTidy\load_translations;
use function TaxonomyTidy\render_missing_dependencies_notice;

/**
 * Verifies bundled Japanese translations and the English fallback.
 */
final class I18nTest extends WP_UnitTestCase {
	/**
	 * Query parameters present before each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get;

	/**
	 * Starts each locale test without a previously loaded catalog.
	 */
	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The test preserves read-only query state; it does not process a request.
		$this->original_get = $_GET;
		$_GET               = array();
		unload_textdomain( 'taxonomy-tidy', true );
	}

	/**
	 * Restores locale filters, text domains, request data, and the current user.
	 */
	public function tear_down(): void {
		remove_filter( 'plugin_locale', array( $this, 'use_japanese_locale' ), 10 );
		remove_filter( 'plugin_locale', array( $this, 'use_english_locale' ), 10 );
		remove_filter( 'load_textdomain_mofile', array( $this, 'use_bundled_japanese_catalog' ), 10 );
		unload_textdomain( 'taxonomy-tidy', true );
		$_GET = $this->original_get;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Japanese locale loads the bundled MO and preserves all inventory controls.
	 */
	public function test_japanese_site_renders_translated_inventory(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		for ( $index = 1; $index <= 52; ++$index ) {
			$term_id = self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => sprintf( 'Locale Inventory %03d', $index ),
				)
			);

			if ( 52 === $index ) {
				$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
				$result  = wp_set_object_terms( $post_id, $term_id, 'post_tag' );
				$this->assertNotWPError( $result );
			}
		}

		add_filter( 'plugin_locale', array( $this, 'use_japanese_locale' ), 10, 2 );
		add_filter( 'load_textdomain_mofile', array( $this, 'use_bundled_japanese_catalog' ), 10, 2 );
		load_translations();

		$_GET = array(
			'taxonomy' => 'post_tag',
			's'        => 'Locale Inventory',
			'orderby'  => 'name',
			'order'    => 'desc',
			'unused'   => '1',
			'paged'    => '2',
		);

		ob_start();
		Plugin::instance()->admin_page()->render();
		$output = (string) ob_get_clean();

		$translations = array(
			'Categories'                                   => 'カテゴリー',
			'Tags'                                         => 'タグ',
			'Category'                                     => 'カテゴリー',
			'Tag'                                          => 'タグ',
			'Taxonomy views'                               => 'カテゴリーとタグの表示切り替え',
			'Search and filter'                            => '検索・絞り込み',
			'Active conditions'                            => '適用中の条件',
			'Search and filter terms'                      => 'カテゴリー・タグの検索・絞り込み',
			'Find by keyword'                              => 'キーワードで探す',
			'Keyword'                                      => 'キーワード',
			'Search by name or slug.'                      => '名前またはスラッグから検索できます',
			'Filter displayed terms'                       => '表示対象を絞る',
			'Sort order'                                   => '並び順',
			'Sort by'                                      => '並び替え対象',
			'Name'                                         => '名前',
			'Published posts'                              => '公開済み投稿数',
			'Direction'                                    => '並び順',
			'Ascending'                                    => '昇順',
			'Descending'                                   => '降順',
			'Globally unused only'                         => '完全に未使用のみ',
			'Apply conditions'                             => '条件を適用',
			'Reset conditions'                             => '条件をリセット',
			'Keyword: %s'                                  => 'キーワード：%s',
			'No active conditions'                         => '条件なし',
			'Slug'                                         => 'スラッグ',
			'Type'                                         => '種別',
			'Parent category'                              => '親カテゴリー',
			'Total relationships'                          => '全体の使用数',
			'Usage'                                        => '利用状況',
			'No terms found.'                              => '条件に一致するカテゴリー・タグはありません',
			'Used by published posts'                      => '公開済み投稿で使用中',
			'Used outside published posts'                 => '公開済み投稿以外で使用中',
			'Globally unused'                              => '完全に未使用',
			'Previous'                                     => '前へ',
			'Next'                                         => '次へ',
			'You are not allowed to access Taxonomy Tidy.' => 'Taxonomy Tidyへアクセスする権限がありません。',
			'Access denied'                                => 'アクセス拒否',
		);

		foreach ( $translations as $source => $translation ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- The test intentionally checks every literal catalog entry through one assertion loop.
			$this->assertSame( $translation, __( $source, 'taxonomy-tidy' ) );
		}
		$this->assertStringContainsString( '検索・絞り込み', $output );
		$this->assertStringContainsString( 'キーワードで探す', $output );
		$this->assertStringContainsString( '3件の条件を適用中', $output );
		$this->assertStringContainsString( 'キーワード：Locale Inventory', $output );
		$this->assertStringContainsString( '名前 · 降順', $output );
		$this->assertStringContainsString( '条件を適用', $output );
		$this->assertStringContainsString( '条件をリセット', $output );
		$this->assertStringContainsString( '公開済み投稿数', $output );
		$this->assertStringContainsString( '全体の使用数', $output );
		$this->assertStringContainsString( '完全に未使用', $output );
		$this->assertStringContainsString( 'Locale Inventory 001', $output );
		$this->assertStringNotContainsString( 'Locale Inventory 002', $output );
		$this->assertStringContainsString( '前へ', $output );

		foreach ( array( 'Categories', 'Tags', 'Search and filter', 'Find by keyword', 'Published posts', 'Total relationships', 'No terms found.' ) as $english ) {
			$this->assertStringNotContainsString( ">{$english}<", $output );
		}

		ob_start();
		render_missing_dependencies_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'Taxonomy Tidyを起動できませんでした', $notice );
	}

	/**
	 * English locale uses source strings when no English translation is bundled.
	 */
	public function test_english_site_uses_source_strings(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		add_filter( 'plugin_locale', array( $this, 'use_english_locale' ), 10, 2 );
		load_translations();

		ob_start();
		Plugin::instance()->admin_page()->render();
		$output = (string) ob_get_clean();

		$this->assertSame( 'Categories', __( 'Categories', 'taxonomy-tidy' ) );
		$this->assertStringContainsString( 'Search and filter', $output );
		$this->assertStringContainsString( 'Find by keyword', $output );
		$this->assertStringContainsString( 'Apply conditions', $output );
		$this->assertStringContainsString( 'Published posts', $output );
		$this->assertStringContainsString( 'Total relationships', $output );
		$this->assertStringNotContainsString( '検索・絞り込み', $output );
	}

	/**
	 * Selects Japanese for this plugin domain.
	 *
	 * @param string $locale Current plugin locale.
	 * @param string $domain Text domain being loaded.
	 */
	public function use_japanese_locale( string $locale, string $domain ): string {
		return 'taxonomy-tidy' === $domain ? 'ja' : $locale;
	}

	/**
	 * Selects English for this plugin domain.
	 *
	 * @param string $locale Current plugin locale.
	 * @param string $domain Text domain being loaded.
	 */
	public function use_english_locale( string $locale, string $domain ): string {
		return 'taxonomy-tidy' === $domain ? 'en_US' : $locale;
	}

	/**
	 * Points the test loader at the bundled catalog outside the test WP plugin dir.
	 *
	 * @param string $mofile Requested translation file.
	 * @param string $domain Text domain being loaded.
	 */
	public function use_bundled_japanese_catalog( string $mofile, string $domain ): string {
		if ( 'taxonomy-tidy' !== $domain ) {
			return $mofile;
		}

		return dirname( TAXONOMY_TIDY_PLUGIN_FILE ) . '/languages/taxonomy-tidy-ja.mo';
	}
}
