<?php
/**
 * Plugin translation integration tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use TermSteward\Admin\ErrorMessages;
use TermSteward\Admin\Page;
use TermSteward\Admin\PlanController;
use TermSteward\Application\Planning\PlanErrorCode;
use TermSteward\Plugin;
use WP_UnitTestCase;

use function TermSteward\term_steward_load_translations;
use function TermSteward\term_steward_render_missing_dependencies_notice;

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
		unload_textdomain( 'term-steward', true );
	}

	/**
	 * Restores locale filters, text domains, request data, and the current user.
	 */
	public function tear_down(): void {
		remove_filter( 'plugin_locale', array( $this, 'use_japanese_locale' ), 10 );
		remove_filter( 'plugin_locale', array( $this, 'use_english_locale' ), 10 );
		remove_filter( 'load_textdomain_mofile', array( $this, 'use_bundled_japanese_catalog' ), 10 );
		unload_textdomain( 'term-steward', true );
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
		term_steward_load_translations();

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
			'Categories'                                  => 'カテゴリー',
			'Tags'                                        => 'タグ',
			'Category'                                    => 'カテゴリー',
			'Tag'                                         => 'タグ',
			'Taxonomy views'                              => 'カテゴリーとタグの表示切り替え',
			'Search panel'                                => '検索パネル',
			'Active conditions'                           => '適用中の条件',
			'Search and filter terms'                     => 'カテゴリー・タグの検索・絞り込み',
			'Find by keyword'                             => 'キーワードで探す',
			'Keyword'                                     => 'キーワード',
			'Search by name or slug.'                     => '名前またはスラッグから検索できます',
			'Filter displayed terms'                      => '表示対象を絞る',
			'Sort order'                                  => '並び順',
			'Sort by'                                     => '並び替え対象',
			'Name'                                        => '名前',
			'Published posts'                             => '公開済み投稿数',
			'Direction'                                   => '並び順',
			'Ascending'                                   => '昇順',
			'Descending'                                  => '降順',
			'Globally unused only'                        => '完全に未使用のみ',
			'Apply conditions'                            => '条件を適用',
			'Reset conditions'                            => '条件をリセット',
			'Keyword: %s'                                 => 'キーワード：%s',
			'No active conditions'                        => '条件なし',
			'Slug'                                        => 'スラッグ',
			'Type'                                        => '種別',
			'Parent category'                             => '親カテゴリー',
			'Total relationships'                         => '全体の使用数',
			'Usage'                                       => '利用状況',
			'No terms found.'                             => '条件に一致するカテゴリー・タグはありません',
			'Used by published posts'                     => '公開済み投稿で使用中',
			'Used outside published posts'                => '公開済み投稿以外で使用中',
			'Globally unused'                             => '完全に未使用',
			'You are not allowed to access Term Steward.' => 'Term Stewardへアクセスする権限がありません。',
			'Access denied'                               => 'アクセス拒否',
			'Action panel'                                => '処理パネル',
			'Selected targets'                            => '選択中の対象',
			'Action method'                               => '処理方法',
			'Rename'                                      => '名称変更',
			'Merge'                                       => '統合',
			'Delete'                                      => '削除',
			'Changes'                                     => '変更内容',
			'Notices and validation results'              => '注意事項・検証結果',
			'Execute'                                     => '実行する',
			'New slug (optional)'                         => '新しいスラッグ（任意）',
			'Leave blank to keep the current slug.'       => '空欄の場合は変更しません',
			'Select a category or tag to process.'        => '処理するカテゴリーまたはタグを選択してください。',
			'Select an action method.'                    => '処理方法を選択してください。',
			'Enter a new name.'                           => '新しい名前を入力してください。',
			'The slug format is invalid.'                 => 'スラッグの形式が正しくありません。',
			'Operation plan'                              => '操作計画',
			'Review changes'                              => '変更内容を確認',
		);

		foreach ( $translations as $source => $translation ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- The test intentionally checks every literal catalog entry through one assertion loop.
			$this->assertSame( $translation, __( $source, 'term-steward' ) );
		}
		foreach ( $this->planning_error_messages() as $code => $messages ) {
			$this->assertSame( $messages['ja'], ErrorMessages::label( $code ), 'Incorrect Japanese error for ' . $code );
		}
		foreach ( array( '検索パネル', 'キーワードで探す', '3件の条件を適用中', 'キーワード：Locale Inventory', '名前 · 降順', '条件を適用', '条件をリセット', '公開済み投稿数', '全体の使用数', '完全に未使用', '処理パネル', '選択中の対象', '処理方法', '変更内容', '注意事項・検証結果', '計画に追加', 'Locale Inventory 031', '前のページへ' ) as $expected_output ) {
			$this->assertStringContainsString( $expected_output, $output, 'Missing translated output: ' . $expected_output );
		}
		$this->assertStringContainsString( '>Locale Inventory 001</option>', $output );
		$this->assertSame( 1, preg_match( '/<tbody>(.*?)<\/tbody>/s', $output, $table_match ) );
		$this->assertStringNotContainsString( 'Locale Inventory 002', $table_match[1] );

		foreach ( array( 'Categories', 'Tags', 'Search panel', 'Find by keyword', 'Published posts', 'Total relationships', 'No terms found.', 'Action panel', 'Selected targets', 'Action method', 'Changes', 'Execute' ) as $english ) {
			$this->assertStringNotContainsString( ">{$english}<", $output );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- The integration test preserves the request method before simulating a validated POST.
		$original_method = $_SERVER['REQUEST_METHOD'] ?? null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The test preserves POST state before submitting its own valid nonce below.
		$original_post             = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'taxonomy'                  => 'post_tag',
			'plan_command'              => 'add',
			PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
		);
		ob_start();
		Plugin::instance()->admin_page()->render();
		$error_output = (string) ob_get_clean();
		$_POST        = $original_post;
		if ( null === $original_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $original_method;
		}
		$this->assertStringContainsString( '処理するカテゴリーまたはタグを選択してください。', $error_output );
		$this->assertStringNotContainsString( 'Select a category or tag to process.', $error_output );

		ob_start();
		term_steward_render_missing_dependencies_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'Term Stewardを起動できませんでした', $notice );
	}

	/**
	 * Every non-header source string has a Japanese translation.
	 */
	public function test_japanese_catalog_has_no_empty_user_facing_entries(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test fixture; no remote request is involved.
		$catalog = file_get_contents( dirname( TERM_STEWARD_PLUGIN_FILE ) . '/languages/term-steward-ja.po' );
		$this->assertIsString( $catalog );
		$this->assertDoesNotMatchRegularExpression( '/msgid "[^"\n]+"\nmsgstr ""/', $catalog );
	}

	/**
	 * English locale uses source strings when no English translation is bundled.
	 */
	public function test_english_site_uses_source_strings(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		add_filter( 'plugin_locale', array( $this, 'use_english_locale' ), 10, 2 );
		term_steward_load_translations();

		ob_start();
		Plugin::instance()->admin_page()->render();
		$output = (string) ob_get_clean();

		$this->assertSame( 'Categories', __( 'Categories', 'term-steward' ) );
		$this->assertStringContainsString( 'Search panel', $output );
		$this->assertStringContainsString( 'Find by keyword', $output );
		$this->assertStringContainsString( 'Apply conditions', $output );
		$this->assertStringContainsString( 'Published posts', $output );
		$this->assertStringContainsString( 'Total relationships', $output );
		$this->assertStringContainsString( 'Action panel', $output );
		$this->assertStringContainsString( 'Selected targets', $output );
		$this->assertStringContainsString( 'Action method', $output );
		$this->assertStringContainsString( '計画に追加', $output );
		$this->assertStringNotContainsString( '検索・絞り込み', $output );
		foreach ( $this->planning_error_messages() as $code => $messages ) {
			$this->assertSame( $messages['en'], ErrorMessages::label( $code ), 'Incorrect English error for ' . $code );
		}
	}

	/**
	 * The active administration user's locale selects the error catalog.
	 */
	public function test_error_messages_follow_admin_user_locale(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'locale', 'ja' );
		wp_set_current_user( $user_id );
		$this->assertSame( 'ja', get_user_locale( $user_id ) );
		$switched_to_japanese = switch_to_user_locale( $user_id );
		add_filter( 'load_textdomain_mofile', array( $this, 'use_bundled_japanese_catalog' ), 10, 2 );
		unload_textdomain( 'term-steward', true );
		term_steward_load_translations();
		$this->assertSame( '処理するカテゴリーまたはタグを選択してください。', ErrorMessages::label( PlanErrorCode::SELECTION_REQUIRED ) );

		if ( $switched_to_japanese ) {
			$this->assertTrue( restore_previous_locale() );
		}
		remove_filter( 'load_textdomain_mofile', array( $this, 'use_bundled_japanese_catalog' ), 10 );
		unload_textdomain( 'term-steward', true );
		update_user_meta( $user_id, 'locale', 'en_US' );
		$this->assertSame( 'en_US', get_user_locale( $user_id ) );
		$switched_to_english = switch_to_user_locale( $user_id );
		term_steward_load_translations();
		$this->assertSame( 'Select a category or tag to process.', ErrorMessages::label( PlanErrorCode::SELECTION_REQUIRED ) );
		if ( $switched_to_english ) {
			$this->assertTrue( restore_previous_locale() );
		}
	}

	/**
	 * Selects Japanese for this plugin domain.
	 *
	 * @param string $locale Current plugin locale.
	 * @param string $domain Text domain being loaded.
	 */
	public function use_japanese_locale( string $locale, string $domain ): string {
		return 'term-steward' === $domain ? 'ja' : $locale;
	}

	/**
	 * Selects English for this plugin domain.
	 *
	 * @param string $locale Current plugin locale.
	 * @param string $domain Text domain being loaded.
	 */
	public function use_english_locale( string $locale, string $domain ): string {
		return 'term-steward' === $domain ? 'en_US' : $locale;
	}

	/**
	 * Points the test loader at the bundled catalog outside the test WP plugin dir.
	 *
	 * @param string $mofile Requested translation file.
	 * @param string $domain Text domain being loaded.
	 */
	public function use_bundled_japanese_catalog( string $mofile, string $domain ): string {
		if ( 'term-steward' !== $domain ) {
			return $mofile;
		}

		return dirname( TERM_STEWARD_PLUGIN_FILE ) . '/languages/term-steward-ja.mo';
	}

	/**
	 * Returns every safe planning error in English and Japanese.
	 *
	 * @return array<string, array{en: string, ja: string}>
	 */
	private function planning_error_messages(): array {
		return array(
			PlanErrorCode::SELECTION_REQUIRED      => array(
				'en' => 'Select a category or tag to process.',
				'ja' => '処理するカテゴリーまたはタグを選択してください。',
			),
			PlanErrorCode::SELECTION_INVALID       => array(
				'en' => 'The selected category or tag could not be verified. Select it again.',
				'ja' => '選択されたカテゴリーまたはタグを確認できません。もう一度選択してください。',
			),
			PlanErrorCode::SINGLE_TERM_REQUIRED    => array(
				'en' => 'Select exactly one target when renaming.',
				'ja' => '名称変更では、対象を1件だけ選択してください。',
			),
			PlanErrorCode::OPERATION_REQUIRED      => array(
				'en' => 'Select an action method.',
				'ja' => '処理方法を選択してください。',
			),
			PlanErrorCode::NEW_NAME_REQUIRED       => array(
				'en' => 'Enter a new name.',
				'ja' => '新しい名前を入力してください。',
			),
			PlanErrorCode::INVALID_NAME            => array(
				'en' => 'The new name contains invalid characters.',
				'ja' => '新しい名前に使用できない文字が含まれています。',
			),
			PlanErrorCode::NAME_UNCHANGED          => array(
				'en' => 'Enter a name different from the current name.',
				'ja' => '現在の名前と異なる名前を入力してください。',
			),
			PlanErrorCode::NAME_CONFLICT           => array(
				'en' => 'This name is already in use.',
				'ja' => 'この名前はすでに使用されています。',
			),
			PlanErrorCode::INVALID_SLUG            => array(
				'en' => 'The slug format is invalid.',
				'ja' => 'スラッグの形式が正しくありません。',
			),
			PlanErrorCode::SLUG_CONFLICT           => array(
				'en' => 'This slug is already in use.',
				'ja' => 'このスラッグはすでに使用されています。',
			),
			PlanErrorCode::DESTINATION_REQUIRED    => array(
				'en' => 'Select a merge destination.',
				'ja' => '統合先を選択してください。',
			),
			PlanErrorCode::SAME_SOURCE_DESTINATION => array(
				'en' => 'The merge destination cannot be the same as its source.',
				'ja' => '統合元と同じ分類は指定できません。',
			),
			PlanErrorCode::TAXONOMY_MISMATCH       => array(
				'en' => 'Categories and tags cannot be merged with each other.',
				'ja' => 'カテゴリーとタグをまたいで統合することはできません。',
			),
			PlanErrorCode::DESCENDANT_DESTINATION  => array(
				'en' => 'A category cannot be merged into one of its descendants.',
				'ja' => '子孫カテゴリーへ統合することはできません。',
			),
			PlanErrorCode::CIRCULAR_HIERARCHY      => array(
				'en' => 'The category hierarchy would become circular, so the merge cannot continue.',
				'ja' => 'カテゴリー階層が循環するため統合できません。',
			),
			PlanErrorCode::TERM_IN_USE             => array(
				'en' => 'A category or tag that is in use cannot be deleted.',
				'ja' => '使用中のカテゴリー・タグは削除できません。',
			),
			PlanErrorCode::DEFAULT_CATEGORY        => array(
				'en' => 'The default category cannot be deleted.',
				'ja' => 'デフォルトカテゴリーは削除できません。',
			),
			PlanErrorCode::STALE_PREVIEW           => array(
				'en' => 'The taxonomy state has changed. Review the plan again.',
				'ja' => '分類の状態が変更されました。内容を確認し直してください。',
			),
			PlanErrorCode::PLAN_CONFLICT           => array(
				'en' => 'The operation plan contains conflicting actions.',
				'ja' => '操作計画に競合する処理が含まれています。',
			),
			PlanErrorCode::PLAN_INVALID            => array(
				'en' => 'The saved operation plan is no longer available. Review it again.',
				'ja' => '保存済みの操作計画を利用できません。内容を確認し直してください。',
			),
			PlanErrorCode::PERMISSION_DENIED       => array(
				'en' => 'You do not have permission to perform this action.',
				'ja' => 'この操作を行う権限がありません。',
			),
			PlanErrorCode::INVALID_NONCE           => array(
				'en' => 'This action has expired. Reload the page and try again.',
				'ja' => '操作の有効期限が切れました。画面を再読み込みしてください。',
			),
			PlanErrorCode::UNKNOWN_ERROR           => array(
				'en' => 'The operation could not continue. Please try again.',
				'ja' => '処理を続行できませんでした。もう一度お試しください。',
			),
		);
	}
}
