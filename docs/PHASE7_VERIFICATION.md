# Phase 7 管理画面検証記録

## 開始時点

Phase 1〜6 は `docs/IMPLEMENTATION_PLAN.md` で Completed、Phase 7 は Not started だった。一方、開始時の実装には4タブ、操作計画の合算表示、一括プレビュー・実行、履歴、Undo、20/50/100件表示、数値ページネーションが既に存在していた。Git の HEAD の件名も `phase7 completed` であり、文書状態とコミット名は一致していなかった。

この記録ではコミット名を完了根拠にせず、現在の要件、実装、テスト結果を根拠に判定する。

## 確認した実装

- カテゴリー、タグ、操作計画、操作履歴の4タブと現在位置
- 管理者ごとの category / post_tag の draft 項目合算バッジ
- 検索パネル、処理パネル、一覧の順序と初期閉状態
- 検索、並べ替え、未使用絞り込み、20/50/100件表示、数値ページネーション
- 計画項目の個別削除、全破棄、計画変更時のプレビュー無効化
- taxonomy ごとの Operation を維持した一括プレビュー・実行
- 実行中の有界バッチ、再開、二重実行防止、終端結果の区別
- owner、capability、nonce、taxonomy、状態、ハッシュ、フィンガープリント、ロックの検証
- 実行開始済み Operation だけの履歴と、安全確認後の Undo
- 投稿詳細を初期 DOM に描画しない遅延取得
- 日本語カタログ、escape、狭幅 CSS、モーダルのフォーカス制御

## Phase 7 で補完した点

- アコーディオンの `aria-expanded` を初期状態と開閉時の両方で同期
- 一覧、操作計画、履歴テーブルをキーボードフォーカス可能な横スクロール領域へ配置
- 履歴テーブルの全見出しへ `scope="col"` を付与
- 親なしカテゴリーを「なし」、階層を持たないタグを「対象外」と表示
- 数値列の右揃えと tabular numbers、長い名前・slug の折り返しを追加
- タブを狭い画面で折り返すように調整
- 名前と公開済み投稿数のソート可能状態、方向、`aria-sort` を一覧見出しへ表示
- README に目的、対象、対象外、対応環境、起動、テスト、有効化、安全上の注意を記載

## 自動検証

開始前の `make check` は次の結果だった。

- PHPCS: 59 / 59 files passed
- JavaScript lint: passed
- PHPUnit / WordPress integration: 88 tests, 1,214 assertions passed

変更後の最終結果は次のとおり。

- PHPCS: 59 / 59 files passed
- JavaScript lint: passed
- PHPUnit / WordPress integration: 89 tests, 1,225 assertions passed
- PHP syntax: `src/`、`tests/`、`bootstrap/` passed
- Composer validation: `composer.json` and lock passed (`--strict`)
- Docker Compose configuration: passed
- `git diff --check`: passed

## 手動確認

Docker の WordPress 6.6.2 / PHP 8.2.25 / MySQL 8.0 環境で次を確認した。

- Taxonomy Tidy 0.1.0 が Active
- 管理画面 URL が未認証アクセスを WordPress ログイン画面へリダイレクト
- 管理者コンテキストのサーバーレンダリングで検索パネル、処理パネル、一覧、初期 `aria-expanded="false"` を出力

ブラウザ実行系は作業環境内で検出できなかったため、実ブラウザでの視覚、狭幅、キーボード、フォーカス復帰、スクリーンリーダー確認は未実施である。既存の Docker 管理画面で行われた Phase 5/6 の実行・Undo スモーク結果は `docs/EXECUTION.md` と `docs/UNDO.md` に記録されているが、今回の CSS/ARIA 変更後の実ブラウザ確認の代替とは扱わない。

## 現時点の判定

実ブラウザでの主要導線確認が残るため、Phase 7 は `In progress` とする。Phase 8 の配布 ZIP、互換性マトリクス、新規 WordPress へのインストール確認はこのフェーズでは実施しない。
