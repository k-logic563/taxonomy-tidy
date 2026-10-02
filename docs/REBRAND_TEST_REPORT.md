# Term Steward 0.1.0 リブランド検証記録

実施日: 2026-10-01（Asia/Tokyo）

## 対象と開始状態

- branch: `main`
- 開始時HEAD: `1a93397b41a654a21febfadb0283562f9eaaa052`
- HEAD tag: `v0.1.0`
- remote: `https://github.com/k-logic563/taxonomy-tidy.git`
- 開始時作業ツリー: clean
- 既存配布物: `dist/taxonomy-tidy-0.1.0.zip`と過去RC ZIP、checksum
- 外部操作: commit、tag、push、Release、WordPress.org公開は未実施

旧開発名称はTaxonomy Tidy、現製品名称はTerm Stewardである。2026-09-29以前の日付付き資料は旧名称で実施した証跡として保持し、このリブランド後検証へ遡及適用していない。

## 識別子と永続化

- Plugin Name: `Term Steward`
- slug／directory／Text Domain: `term-steward`
- main file: `term-steward.php`
- PHP namespace: `TermSteward`
- constant prefix: `TERM_STEWARD_`
- snake_case／DB／Ajax／nonce prefix: `term_steward_`
- CSS class／asset handle prefix: `term-steward`
- Composer package: `klogic563/term-steward`
- GitHub URL: `https://github.com/k-logic563/term-steward`

新規有効化では`wp_term_steward_operations`、`wp_term_steward_operation_items`、`wp_term_steward_changes`と`term_steward_schema_version=1`を作成した。旧namespaceはautoloadされず、旧Ajax hookは登録されず、旧optionと旧テーブルを読み書きしない統合テストが成功した。旧データの移行・削除処理は追加していない。

## 自動検証

- `make check`: 成功
  - PHPCS: 64 / 64 files
  - JavaScript lint: 成功
  - PHPUnit／WordPress統合テスト: 120 tests / 1,495 assertions
  - Ajax PHPUnit: 1 test / 6 assertions
- Playwright E2E: 成功
  - setup 1件＋E2E-001〜008の計9件
  - 名称変更、統合、削除、操作計画、プレビュー、履歴、Undoを確認
  - 未処理JavaScriptエラー、予期しないHTTPエラー、request failureは0件
- `git diff --check`: 成功
- Composer validate: 成功
- 翻訳: 新しいPOからMOを再生成し、`msgfmt --check`成功

初回`make check`は再作成直後のテストDB接続と、共有テストライブラリvolumeに残った旧DB名のため開始前に停止した。既存volumeでも現在の環境変数からテスト設定を更新するよう導入スクリプトを修正し、最終一括実行は成功した。初回E2Eは8081番ポート競合で開始前に停止し、別Compose projectと18081番ポートで全9件を再実行して成功した。最終コードでの再実行では8件成功後、E2E-006だけが製品assertionではなくChromium context終了時にtimeoutしたため、同ケースを単独再試験し9.3秒で成功した。

## RC ZIP

- path: `dist/term-steward-0.1.0.zip`
- SHA-256: `bd0e4a960beb3faaa2734557e05b71f91013108c3e5a9b60e5b3e92c42790ffb`
- checksum照合: 成功
- `unzip -t`: 成功
- ZIP直下: `term-steward/`だけ
- main file: `term-steward/term-steward.php`
- 旧メインファイル: なし
- 旧製品名・旧識別子: ZIP内0件

ソースをマウントしない新規WordPress環境へRC ZIPをインストールして有効化した。プラグイン一覧はName `Term Steward`、Version `0.1.0`、Status `Active`を返した。管理画面のサーバー描画でTerm Steward名称、カテゴリー、タグ、操作計画、操作履歴の4タブを確認し、旧製品名は表示されなかった。WordPressコンテナログにPHP Warning、Notice、Deprecated、Fatal、uncaught error、stack traceはなかった。

## 残存事項

- 既存remoteは旧GitHub URLのまま。リポジトリ改名は外部操作のため未実施。
- 過去の試験報告には旧名称、旧slug、旧ZIP名、旧checksumが履歴として残る。各文書冒頭に旧開発名称での証跡であることを明記した。
- 全72件の過去手動試験をリブランド後RCで再実施したわけではない。機能回帰は全統合テストとE2E-001〜008、RC新規導入スモークで確認した。
