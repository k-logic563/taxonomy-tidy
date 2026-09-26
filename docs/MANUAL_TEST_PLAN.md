# Taxonomy Tidy 0.1.0 実ブラウザ手動テスト計画

## 1. 目的と適用範囲

この文書は、Taxonomy Tidy 0.1.0をWordPressプラグインとして公開する前に、リリース候補ZIPを実ブラウザで受け入れ確認するための手順書兼記録票である。対象は現在実装済みの標準`category`、標準`post_tag`、公開済み標準投稿へのrelationship変更、名称・明示的slug変更、同一taxonomy内の統合、完全未使用termの削除、操作計画、プレビュー、有界バッチ、履歴、Undoである。対象外のカスタムtaxonomy、カスタム投稿タイプ固有処理、CSV、Redo、定期実行は試験対象にしない。

本計画は`AGENTS.md`、`REQUIREMENTS.md`、`docs/IMPLEMENTATION_PLAN.md`、関連資料、現在のPHP・JavaScript・CSS、全統合テスト、シード実装、配布スクリプトを照合して作成した。既存の自動テストと重なる項目も、ブラウザ状態、視覚表示、フォーカス、実通信、自動継続を確認する価値がある場合は含める。

### 現在のリリース判定上の位置付け

- Phase 1～6: `Completed`
- Phase 7: `In progress`（現行UIの実ブラウザ確認未完了）
- Phase 8: `Blocked`
- 現在の判定: `No-Go`
- 2026-09-26最新集計: Critical 成功44・失敗0・保留0、High 成功25・失敗1・保留2
- 主な解除条件: MT-069の狭幅不具合を修正・回帰し、MT-006の保持方針を正本へ明記し、MT-070を実スクリーンリーダーで完了すること

### 優先度

- **Critical**: 失敗した場合は公開不可
- **High**: 公開前に原則修正
- **Medium**: 軽微、または既知の制限として公開後対応を検討可能

### 共通の実施結果欄

各ケースの初期値は`未実施`。実施時に1つだけ選び、失敗・保留では備考に理由、再現率、影響範囲、関連Issueを記録する。

## 2. テスト環境記録票

| 項目 | 記録 | 確認 |
|---|---|---|
| WordPressバージョン | 6.6.2 | [x] |
| PHPバージョン | 8.2.28 | [x] |
| MySQL／MariaDBとバージョン | MySQL 8.0.46 | [x] |
| Taxonomy Tidyバージョン | 0.1.0 | [x] |
| 使用した配布ZIP名 | `taxonomy-tidy-0.1.0.zip` | [x] |
| ZIP SHA-256 | `6612c00c498387cd76d0cf1b045fb713de1132f2dab264fb6726fa939718e5ba` | [x] |
| OS | macOS 26.6.2 arm64（Docker host） | [x] |
| ブラウザとバージョン | Chrome for Testing 153.0.8010.12、Firefox 155.0、Playwright WebKit build 2359 | [x] |
| テスト実施日時（WordPress設定タイムゾーンを併記） | 2026-09-20～2026-09-21、Asia/Tokyo | [x] |
| テスト担当者 | Codex（実ブラウザ操作支援） | [x] |
| インストール種別 | [x] 新規 / [ ] 更新 | [x] |
| WordPressサイト言語／管理者言語 | 日本語／日本語 | [x] |
| `WP_DEBUG`／`WP_DEBUG_LOG` | [x] true / [ ] false | [x] |
| Docker Composeプロジェクト名 | `taxonomy-tidy-manual-20260920`、`taxonomy-tidy-recovery-mt063-20260920` | [x] |
| Git commit（ZIP生成元） | `0f8549adbc48fb4882d6f57af9023f7edc0f4d16` | [x] |

### 最低実施マトリクス

| 環境 | 用途 | 必須範囲 |
|---|---|---|
| WordPress 6.6.x / PHP 8.2 / MySQL 8.0 / 最新Chrome系 | 最低対応・主試験 | 全Critical・High |
| 現行安定版WordPress / PHP 8.4 / MySQL 8.0 / Firefox | 現行互換・ブラウザ差 | インストール、主要導線、モーダル、Undo、ログ |
| WordPress 6.6.x / PHP 8.2 / MariaDB 10.11+ / SafariまたはWebKit | DB・ブラウザ差 | インストール、一覧、名称変更、統合、削除、Undo |

正式公開時の「現行安定版」は試験日に再確認して記録する。過去資料のWordPress 7.1.1は2026-09-19時点の記録であり、本書では固定値として扱わない。

## 3. 環境構築と事前確認

### 3.1 リリース候補の生成と導入

1. 作業ツリーと対象commitを記録する。既存の未コミット変更を勝手に破棄しない。
2. `make check`が成功した同じソースから`make dist`を実行する。
3. `dist/taxonomy-tidy-<version>.zip`と`.sha256`を保全し、以後の主試験では開発ディレクトリをマウントしたプラグインを使わない。
4. ソースをマウントしない新規WordPress環境へ、管理画面の「プラグイン > 新規プラグインを追加 > プラグインのアップロード」からZIPを導入する。
5. `wp-config.php`で`WP_DEBUG`、`WP_DEBUG_LOG`を有効にし、画面表示前のログを退避または空にする。
6. DevToolsのConsoleとNetworkを開き、Preserve logを有効にする。データ変更前後はWordPressの投稿・カテゴリー・タグ画面と、必要に応じてWP-CLIの取得結果を証跡にする。

### 3.2 既存シードの利用

開発用Docker環境では、次の再現可能なシードを使用できる。シードツールは配布ZIPから除外されるため、主試験用のZIP環境へはWP-CLIまたは管理画面で同等データを準備する。

```sh
make seed-clean
make seed-demo
```

`seed-demo`はカテゴリー25件、タグ75件、公開投稿90件、下書き10件、非公開5件、予約5件、固定ページ5件を作る。`WordPress`／`wordpress`／`WP`／`ワードプレス`、`JavaScript`／`Javascript`／`JS`、`SEO`／`seo`、`Web制作`／`WEB制作`、親子カテゴリー、完全未使用term、公開・下書き・対象外オブジェクト用タグ、統合元と統合先が同じ投稿に付与済みの状態を含む。同じモードの再実行は冪等である。

大量データ試験は別スナップショットまたは`seed-clean`後に行う。

```sh
make seed-clean
make seed-large
```

`seed-large`はカテゴリー100件、タグ1,000件、公開投稿320件、下書き50件、非公開25件、予約25件を作る。末尾10カテゴリー・100タグは完全未使用、その前の10カテゴリー・100タグは対象外投稿だけで使用される。demoとlargeは同時利用できない。

### 3.3 機能試験用の補助フィクスチャ

`seed-demo`だけではカテゴリー側の全利用状態、複数削除、30件超Undoを明示的に保証しない。次の名前を接頭辞`MT-`付きで管理画面またはWP-CLIから追加し、作成したIDを別紙へ記録する。既定カテゴリーはサイトの「投稿設定」で確認し、名前を変更せず`MT-CAT-DEFAULT`という試験上の別名で参照する。

| 種類 | 名前 | 必要な状態 |
|---|---|---|
| カテゴリー | `MT-CAT-PUB` | 公開投稿だけで使用 |
| カテゴリー | `MT-CAT-DRAFT` | 下書きだけで使用 |
| カテゴリー | `MT-CAT-BOTH` | 公開投稿と下書きの両方で使用 |
| カテゴリー | `MT-CAT-UNUSED-01`～`15` | 全オブジェクトで未使用 |
| カテゴリー | `MT-CAT-PARENT`、`MT-CAT-CHILD` | 親子関係あり。親は公開投稿にも付与 |
| カテゴリー | `MT-CAT-MERGE-A`、`B`、`TARGET` | 同一投稿でA・B・TARGETが付与済みのケースと、A/Bだけのケースを作る |
| カテゴリー | `MT-CAT-EXCLUDED` | 公開投稿と下書きに付与し、統合後もtermを保持すべき状態 |
| カテゴリー | `WordPress記事`、`WP記事` | 表記揺れ候補 |
| カテゴリー | `MT-CAT-UNRELATED` | 統合対象投稿へ付与し、無関係assignment保持の確認に使用 |
| タグ | `MT-TAG-PUB` | 公開投稿だけで使用 |
| タグ | `MT-TAG-DRAFT` | 下書きだけで使用 |
| タグ | `MT-TAG-BOTH` | 公開投稿と下書きの両方で使用 |
| タグ | `MT-TAG-MULTI` | 3件以上の公開投稿で重複使用 |
| タグ | `MT-TAG-UNUSED-01`～`15` | 全オブジェクトで未使用 |
| タグ | `MT-TAG-MERGE-A`、`B`、`TARGET` | 同一投稿でA・B・TARGETが付与済みのケースと、A/Bだけのケースを作る |
| タグ | `MT-TAG-EXCLUDED` | 公開投稿と下書きに付与し、統合後もtermを保持すべき状態 |
| タグ | `WordPress`、`wordpress`、`WP` | 表記揺れ候補。demoを使う場合は既存を利用 |
| タグ | `MT-TAG-UNDO-SOURCE`、`TARGET` | 30件以上の公開投稿へSOURCEを付与。TARGETは一部投稿へ事前付与 |
| タグ | `MT-TAG-UNRELATED` | 統合対象投稿へ付与し、無関係assignment保持の確認に使用 |

投稿は最低限、次を作る。各投稿の初期term一覧をスクリーンショットまたはCSV相当のWP-CLI出力で保存する。

| 投稿 | 状態／種類 | 主な割り当て |
|---|---|---|
| `MT-PUB-01` | 公開／post | CAT-MERGE-A・B・TARGET・UNRELATED、TAG-MERGE-A・B・TARGET・UNRELATED |
| `MT-PUB-02` | 公開／post | CAT/TAG-MERGE-A・UNRELATED（TARGETなし） |
| `MT-PUB-03` | 公開／post | CAT/TAG-MERGE-B・UNRELATED（TARGETなし） |
| `MT-DRAFT-01` | 下書き／post | CAT/TAG-DRAFT、BOTH、EXCLUDED |
| `MT-PRIVATE-01` | 非公開／post | EXCLUDED系term |
| `MT-FUTURE-01` | 予約／post | EXCLUDED系term |
| `MT-PENDING-01` | 承認待ち／post | EXCLUDED系term |
| `MT-TRASH-01` | ゴミ箱／post | EXCLUDED系term |
| `MT-PAGE-01` | 公開／page | EXCLUDED系term（タグ付与はWP-CLIで作成） |
| `MT-UNDO-PUB-01`～`35` | 公開／post | `MT-TAG-UNDO-SOURCE`。01～05だけTARGETも事前付与 |

### 3.4 比較用ベースライン

- [x] カテゴリー・タグごとの公開済み標準投稿数と全relationship数を記録した。
- [x] 各投稿のカテゴリー・タグ割り当てを記録した。
- [x] 既定カテゴリーIDを記録した。
- [x] `wp_taxonomy_tidy_operations`、`wp_taxonomy_tidy_operation_items`、`wp_taxonomy_tidy_changes`の初期件数を記録した。
- [x] ブラウザConsole、Network、`wp-content/debug.log`、PHPエラーログの開始状態を記録した。
- [ ] DBまたはDocker volumeの復元可能なスナップショットを取得した。

最後の項目は保留とした。DB全体のexportにはテスト利用者のcredential hashが含まれるため保全せず、秘密情報を含まないfixture baselineとケース群ごとの再seedで代替した。

## 4. 実装・資料の差異と試験上の扱い

1. 操作ボタンは依頼文の「計画を追加する」ではなく、現行実装では「計画に追加」。本書は現行実装の文言を期待値とする。
2. `AGENTS.md`にはプレビュー主要操作として「整理を実行」、`REQUIREMENTS.md`と現行の操作計画モーダルには「実行」がある。本書は現行実装の「キャンセル」「実行」を期待値とし、仕様文言の統一は別途判断事項とする。
3. 過去資料には1,000タグの統合先候補全件描画を受け入れ条件違反とした記録があるが、その後の製品判断で、入力不要の単一selectへ同一taxonomy全件を描画する方式を既知の制限として許容している。本書では「一覧はページングされる」「統合先selectは1,000件でも操作不能にならない」の両方を確認する。
4. アンインストール時は監査・復旧用3テーブルを保持する、と`docs/TEST_REPORT.md`と`docs/RELEASE_CHECKLIST.md`に記録されている。一方で専用`uninstall.php`やアンインストールhookはなく、`REQUIREMENTS.md`に保持方針の明文化もない。本書では現行挙動（保持）を確認し、公開前に方針文書の正本を確定する。
5. 正式な旧配布版が存在しないため、「既存バージョンから更新」は実リリース間移行としては未検証。現行0.1.0の再インストール／スキーマ再適用によるデータ保持を代替確認し、初回更新リリース時に実版間試験を追加する。
6. Phase 7は実装と自動テストが存在するが実ブラウザ確認未完了のため`In progress`、Phase 8は`Blocked`である。本書完了前に状態をCompletedへ変更しない。

## 5. テストケース

証跡は、原則として画面全体と要点のスクリーンショット、Network要求／応答、Console、変更前後の投稿編集画面またはWP-CLI出力、必要な場合だけ個人情報を除いたログを残す。nonce、Cookie、パスワード、秘密情報は保存しない。

### A. 配布ZIP、インストール、基本表示、権限

#### MT-001 [Critical] 配布ZIPの構成と完全性

- 対象機能: 配布物
- 目的: 開発物・秘密情報を含まず、実行に必要なファイルを含む自己完結ZIPであることを確認する。
- 事前条件: `make check`成功後に同じcommitから`make dist`でRC ZIPを生成済み。
- 操作手順: (1) `.sha256`でZIPを検証する。(2) `unzip -t`を実行する。(3) 展開し、直下が`taxonomy-tidy/`1つであることを確認する。(4) `taxonomy-tidy.php`、`src/`、`assets/`、`languages/`、`vendor/autoload.php`、`README.md`、`CHANGELOG.md`、`LICENSE`を確認する。(5) `.env*`、`.git*`、IDE設定、`tests/`、`tools/`、`docs/`、Docker、Node、開発用Composer依存、認証情報がないことを確認する。
- 期待結果: ZIP破損がなく、必要ファイルだけが含まれ、バージョンとZIP名が一致する。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: docs/MANUAL_TEST_REPORT.md「事前ゲート」
- 備考: `.distignore`と`bin/build-dist.sh`の現行ルールも比較する。 【2026-09-20実施】同一commitでmake checkとmake distを実行。ZIP検査・SHA-256照合とも成功。

#### MT-002 [Critical] 新規インストール、有効化、初回スキーマ作成

- 対象機能: インストール／有効化
- 目的: RC ZIPだけから警告なく起動し、必要な永続化領域を作成できることを確認する。
- 事前条件: ソースをマウントしていない新規WordPress、空のdebug.log。
- 操作手順: (1) 管理画面からRC ZIPをアップロードしてインストールする。(2) 有効化する。(3) プラグイン一覧と「ツール」メニューを確認する。(4) Taxonomy Tidyを開く。(5) DBで3テーブルとschema optionを確認する。(6) Console、Network、debug.log、PHPログを確認する。
- 期待結果: PHP Warning／Notice／Deprecated／Fatal、JSエラー、失敗リクエストがなく、operations・operation_items・changesの3テーブルとスキーマversion 1が作られる。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/MT-002-result.json、MT-002-activated.png
- 備考: WordPress最低対応環境で必須。 【2026-09-20実施】管理画面からRC ZIPをアップロードして有効化。3テーブルとschema version 1を確認。

#### MT-003 [High] 管理者アクセスと4タブ

- 対象機能: 管理画面
- 目的: 必要な3 capabilityを持つ利用者が全ビューへ到達できることを確認する。
- 事前条件: 管理者でログイン済み。
- 操作手順: (1) 「ツール > Taxonomy Tidy」を開く。(2) カテゴリー、タグ、操作計画、操作履歴を順にクリックする。(3) 戻る／進む、再読み込みも行う。
- 期待結果: 4タブが表示され、現在タブが識別でき、403・白画面・自動モーダル表示がない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json、MT-003-four-tabs.png
- 備考: 操作計画のバッジは現在利用者のdraft件数だけを示す。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-004 [Critical] 権限不足利用者の拒否

- 対象機能: アクセス制御
- 目的: 3 capabilityの一部しか持たない利用者がUIと直接URL、保存済み操作へアクセスできないことを確認する。
- 事前条件: subscriberへ`manage_categories`だけを追加した利用者を用意。
- 操作手順: (1) 権限不足利用者でログインする。(2) 「ツール」メニューを確認する。(3) `tools.php?page=taxonomy-tidy`を直接開く。(4) 管理者で取得した履歴詳細URLを開く。(5) DevToolsから操作POST/Ajax URLを再送しない範囲で、別ユーザーの画面到達を確認する。
- 期待結果: メニューが表示されず、直接アクセスと保存済み操作アクセスも403相当で拒否され、内部情報を表示しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json、MT-004-forbidden.png
- 備考: 必須capabilityは`manage_categories`、`edit_others_posts`、`edit_published_posts`。 【2026-09-20実施】capability不足利用者でメニュー非表示と直接URL拒否を確認。

#### MT-005 [High] 日本語UIと実データ件数

- 対象機能: 国際化／一覧
- 目的: 日本語管理者では利用者向け英語が混在せず、表示件数が実データと一致することを確認する。
- 事前条件: サイト言語と管理者言語を日本語、demo＋補助フィクスチャを準備。
- 操作手順: (1) 4タブ、検索・処理パネル、一覧、計画、プレビュー、履歴、Undo画面を巡回する。(2) 表の公開済み投稿数、全relationship数、利用状況をWordPress画面またはWP-CLIのベースラインと比較する。(3) ConsoleとNetwork応答にも利用者向け英語が露出しないか確認する。
- 期待結果: 固有名詞Taxonomy Tidy以外に意図しない英語がなく、カテゴリー／タグ、公開済み標準投稿数、全relationship数が一致する。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json
- 備考: 英語ソース文字列は日本語MOで翻訳される実装。 【2026-09-20実施】日本語UIと代表termの表示件数をベースラインに照合。

#### MT-006 [High] 無効化、再有効化、再インストール、アンインストール

- 対象機能: ライフサイクル／データ保持
- 目的: ライフサイクル操作で履歴・設定・テーブルが意図せず壊れないことを確認する。
- 事前条件: 完了済み操作と履歴を最低1件作成し、3テーブルの件数を記録。
- 操作手順: (1) 無効化して再有効化し、履歴と画面を確認する。(2) 同じRC ZIPで再インストールまたは上書き更新する。(3) スキーマ再適用後の件数を確認する。(4) DBバックアップ後に管理画面から削除（アンインストール）する。(5) 3テーブルの現行挙動を確認し、再インストールする。
- 期待結果: 無効化・再有効化・同版再適用で履歴と設定が保持され、重複テーブルを作らない。アンインストールでは現行文書どおり監査・復旧用3テーブルを保持する。
- 実施結果: [ ] 未実施 [ ] 成功 [ ] 失敗 [x] 保留
- 証跡: build/manual-test/high-completion/evidence/group-a/results.json、MT-006-before-uninstall.sql、各スクリーンショット
- 備考: 【2026-09-26再試験】無効化・再有効化、同一RC ZIP上書き、DBバックアップ、管理画面削除、再導入を完了。3テーブル、schema option、履歴は全段階で保持され、再導入後も履歴を再利用でき、実挙動は成功した。ただし`REQUIREMENTS.md`にアンインストール時の3テーブル／schema option保持、履歴再利用、利用者への告知方針が定義されていないため、正本確定まで保留とする。

### B. 検索、絞り込み、並び替え、ページネーション

#### MT-007 [High] 名前・slugキーワード検索

- 対象機能: 検索
- 目的: 名前とslugの部分一致検索をtaxonomy別に確認する。
- 事前条件: demoデータ、検索対象の名前とslugを記録。
- 操作手順: (1) カテゴリーで日本語名の一部を検索する。(2) slugだけに含まれる文字列を検索する。(3) タグで大文字小文字・日本語の代表語を検索する。(4) 前後空白を含む入力も試す。
- 期待結果: 名前またはslugに一致する同一taxonomyのtermだけが表示され、カテゴリーとタグが混在しない。条件要約とURLも一致する。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json、MT-007-search.png
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-008 [High] 並び替えと昇順・降順

- 対象機能: 並び替え
- 目的: 名前・公開済み投稿数の2キーと両方向を確認する。
- 事前条件: 件数差のあるtermを準備。
- 操作手順: (1) 名前の昇順・降順を適用する。(2) 公開済み投稿数の昇順・降順を適用する。(3) 表見出しからも切り替える。(4) 同値行を含め再読み込みする。
- 期待結果: 選択値、見出しの方向表示と`aria-sort`、実際の行順が一致し、ページ間で安定する。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-009 [Critical] 完全未使用の絞り込み

- 対象機能: 安全な未使用判定
- 目的: 公開数0ではなく、全WordPressオブジェクトとのrelationship 0だけを抽出する。
- 事前条件: CAT/TAGのUNUSED、DRAFT、EXCLUDEDを用意。
- 操作手順: (1) カテゴリーで「完全に未使用のみ」を適用する。(2) タグでも行う。(3) UNUSEDは表示、DRAFT・EXCLUDEDは非表示であることを確認する。(4)各件数をベースラインと比較する。
- 期待結果: 全relationship 0のtermだけが表示され、下書き・固定ページ・非公開等だけで使うtermを未使用扱いしない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json
- 備考: 削除安全性に直結する。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-010 [High] 複合条件と条件要約

- 対象機能: 検索／絞り込み
- 目的: キーワード、未使用、並び順を組み合わせても条件と結果が一致することを確認する。
- 事前条件: UNUSED群に共通接頭辞を付与済み。
- 操作手順: (1) `MT-CAT-UNUSED`検索＋未使用＋名前降順を適用する。(2) タグでも同様にする。(3) パネルを閉じ、条件数と要約を確認する。(4) 再度開いて入力値を確認する。
- 期待結果: AND条件で正しい結果となり、条件数、要約、フォーム値、URLが一致する。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-011 [High] 条件のリセット

- 対象機能: 検索／絞り込み
- 目的: リセットがtaxonomyと表示件数以外の条件をクリアすることを確認する。
- 事前条件: MT-010の複合条件を適用済み。
- 操作手順: 「条件をリセット」を押し、検索語、未使用、並び順・方向、ページ番号、条件要約を確認する。
- 期待結果: 検索・絞り込み・非既定ソート・ページ番号が解除され、現在taxonomyと表示件数は維持され、全件表示へ戻る。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/high-completion/evidence/group-b/results.json、MT-011-*.png
- 備考: 【2026-09-26完了】カテゴリー50件表示とタグ100件表示の双方で、検索、未使用、公開数、降順、2ページ目を設定してからリセット。URLは`page`、`taxonomy`、`per_page`だけとなり、条件要約は「条件なし」、表示件数とtaxonomyは維持、データ変更なしを確認。

#### MT-012 [High] 表示件数20・50・100

- 対象機能: ページサイズ
- 目的: 許可された表示件数へ切り替わり、条件を維持することを確認する。
- 事前条件: 100件超のterm、検索条件を1つ適用済み。
- 操作手順: (1) 20、50、100を順に選び「適用」する。(2) 各行数と総件数を確認する。(3) URLを手動で不正値に変更する。
- 期待結果: 各上限以内の行数となり検索条件を維持する。不正値は許可値へ安全に正規化される。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-013 [High] 上下ページネーションと数値・`>`・`>>`

- 対象機能: ページネーション
- 目的: 表の上下で同じページ移動ができることを確認する。
- 事前条件: largeデータ、表示20件。
- 操作手順: (1) 上部で2ページ目の数値を押す。(2) 下部の`>`で次へ進む。(3) 上部の`>>`で最終へ進む。(4) 前方向操作で先頭へ戻る。(5) URL、現在ページ、行内容を比較する。
- 期待結果: 上下の状態が同期し、数値・次・最終・前・先頭が正しいページへ移動する。ページ移動でデータ変更しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json
- 備考: 表示記号はWordPressのローカライズ・管理画面仕様により視覚表現が変わる場合がある。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-014 [High] 先頭・最終・絞り込み後のページ境界

- 対象機能: ページネーション
- 目的: 境界で無効操作が出ず、絞り込み後の範囲外ページを補正することを確認する。
- 事前条件: largeデータのタグ最終ページを表示。
- 操作手順: (1) 最終ページで次・最終の状態を確認する。(2) URL上は最終ページのまま、結果が1ページになるキーワードを適用する。(3) 先頭ページで前・先頭の状態を確認する。(4) 件数表示を確認する。
- 期待結果: 範囲外ページは有効ページへ補正され、空の誤ページや不正な件数を表示しない。境界リンクは適切に無効または非表示となる。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-015 [High] 0件表示と1,000タグ操作性

- 対象機能: 空状態／性能
- 目的: 0件の説明が適切で、1,000タグでもブラウザが操作不能にならないことを確認する。
- 事前条件: largeデータ、DevTools PerformanceまたはNetworkを利用可能。
- 操作手順: (1) 存在しない語で検索する。(2) 空状態、上下pagination、選択状態を確認する。(3) 条件を戻し、タグ画面をCold loadする。(4) 検索パネル、処理パネル、select、スクロール、ページ移動を各10回操作する。(5) DOM行数、HTML転送量、長いタスク、応答時間を記録する。
- 期待結果: 0件の日本語表示となり不正なページリンクを出さない。一覧行はページサイズ以内、統合先候補は同一taxonomy全件を1組だけ含み、フリーズ、操作取りこぼし、JSエラーがない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/high-completion/evidence/group-b/results.json、MT-015-*.png、network.json、console.txt
- 備考: 【2026-09-26完了】0件時の日本語空状態、paginationなし、選択なしを確認。1,000タグのcold load 757.51 ms、転送26,306 bytes、DOMContentLoaded 80.9 ms、20行、DOM 1,797要素、統合先1,000件、反復操作最大930.21 ms、Long Task 0件、予期しないJS／Networkエラー0件。全件select描画は既知の制限として継続。

### C. 処理パネルと操作計画

#### MT-016 [Critical] 標準の計画作成フロー

- 対象機能: 操作計画
- 目的: 必須の7段階フロー以外からデータ変更が起きないことを確認する。
- 事前条件: 未使用termを1件用意。
- 操作手順: (1) カテゴリーまたはタグを選択する。(2) 処理パネルで処理を設定する。(3) 「計画に追加」を押す。(4) 操作計画タブで追加を確認する。(5) 「変更内容を確認」を押す。(6) モーダル内容を確認する。(7a) 一度キャンセルする。(7b) 再度開いて実行する。
- 期待結果: 計画追加・プレビュー・キャンセルまではtermとrelationshipが変わらず、実行後だけ変更される。各画面に次の操作が明確に表示される。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 現行ボタン文言は「計画に追加」。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-017 [Critical] 名称だけの変更計画

- 対象機能: 名称変更
- 目的: 1termだけを対象に名称変更を計画し、slugとrelationshipを計画対象にしないことを確認する。
- 事前条件: 使用中カテゴリーまたはタグのname、slug、割り当てを記録。
- 操作手順: (1) 1termを選択する。(2) 名称変更を選び、新しい名前だけ入力、slugは空にする。(3) 計画へ追加する。(4) 操作計画の対象と変更後を確認する。
- 期待結果: 1件の名称変更が追加され、変更後は新名称だけ、slug維持が分かり、追加時点で実データは不変。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-018 [High] 明示的slug変更計画

- 対象機能: 名称／slug変更
- 目的: slugは明示入力時だけ変更対象になることを確認する。
- 事前条件: 競合しない新name・slugを準備。
- 操作手順: 1termを選び、名称変更で新nameと新slugを入力して計画追加し、操作計画とプレビューを確認する。
- 期待結果: nameとslugの両方が変更予定として表示される。空欄時との違いが明確で、追加時点では不変。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-advanced-workflows.json
- 備考: slug変更は現行実装済み。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-019 [Critical] 1件の統合計画

- 対象機能: 統合
- 目的: 1つのsourceを同一taxonomyの既存destinationへ計画できることを確認する。
- 事前条件: `MT-TAG-MERGE-A`と`TARGET`、公開投稿割り当てを準備。
- 操作手順: sourceを選択し、統合を選び、TARGETを選択して計画へ追加する。操作計画でsource、destination、影響見込みを確認する。
- 期待結果: 統合元と統合先が名前で区別され、内部IDは露出せず、まだrelationshipは変わらない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-020 [Critical] 複数sourceを同じdestinationへ統合する計画

- 対象機能: 複数統合
- 目的: A・Bを1つのdestinationへまとめる1計画を作れることを確認する。
- 事前条件: `MT-CAT-MERGE-A`、`B`、`TARGET`を準備。
- 操作手順: AとBを同時選択し、統合、TARGETを選び計画追加する。選択変更時の統合先候補も確認する。
- 期待結果: source 2件とdestination 1件が同一計画に表示され、A/Bはdestination候補から除外される。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 選択済みdestinationをsourceへ追加した場合は解除理由を日本語表示する。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-021 [Critical] 未使用カテゴリーの複数削除計画

- 対象機能: 複数削除
- 目的: 同一Operation内へ複数カテゴリー削除Itemを作る。
- 事前条件: `MT-CAT-UNUSED-01`～`12`が全relationship 0。
- 操作手順: 12件を選択し、削除を選び、検証表示を確認して計画へ追加する。
- 期待結果: 12対象が同じcategory計画に追加され、termごとの実行ボタンはなく、追加時点で削除されない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 10件を超え内部バッチ境界を通す。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-022 [Critical] 未使用タグの複数削除計画

- 対象機能: 複数削除
- 目的: 同一Operation内へ複数タグ削除Itemを作る。
- 事前条件: `MT-TAG-UNUSED-01`～`15`が全relationship 0。
- 操作手順: 15件を選択し、削除を選び、計画へ追加する。
- 期待結果: 15対象が同じpost_tag計画に追加され、個別実行を要求せず、追加時点で削除されない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-023 [Critical] カテゴリーとタグ計画の同時保持

- 対象機能: 操作計画
- 目的: 内部ではtaxonomy別Operationを維持しつつ、利用者には合算した計画として扱えることを確認する。
- 事前条件: categoryに名称変更、post_tagに統合または削除のdraftを用意。
- 操作手順: (1) カテゴリー計画を追加する。(2) タグタブへ移り別計画を追加する。(3) 操作計画タブを開く。(4) タブバッジ、taxonomy別グループ、合計件数を確認する。
- 期待結果: 両taxonomyの計画が消えず、明確に分離表示され、合計draft件数が正しい。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-advanced-workflows.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-024 [High] 計画項目の削除とデータ不変

- 対象機能: 操作計画編集
- 目的: 不要なdraft項目だけを計画から外し、WordPressデータを変更しないことを確認する。
- 事前条件: 同一taxonomyに2項目以上、他taxonomyにも1項目以上のdraftを用意。
- 操作手順: (1) 操作計画から1項目の「削除」を押す。(2) 残り計画、バッジを確認する。(3) term名、slug、relationship、履歴を確認する。(4) 最後の1項目削除も試す。
- 期待結果: 指定項目だけが消え、他項目・他taxonomyは残り、実データと履歴は変わらない。空draftは件数に含まれない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-advanced-workflows.json
- 備考: 「計画をすべて破棄」の確認ダイアログとキャンセルも併せて確認する。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-025 [High] 対象未選択と名称入力エラー

- 対象機能: 入力検証
- 目的: 対象未選択、新名称空欄、名称不変、複数選択renameを安全に拒否する。
- 事前条件: 処理パネルを開ける状態。
- 操作手順: (1) 未選択で計画追加する。(2) 1件選択、名称変更、新名称空欄で追加する。(3) 現名称と同じ値で追加する。(4) 2件選択して名称変更を試す。
- 期待結果: 各操作を追加せず、該当する選択／変更内容セクション内に日本語エラーを表示し、修正可能な入力またはエラーへフォーカスする。termは不変。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/high-completion/evidence/group-c/results.json、MT-025-*.png
- 備考: 【2026-09-26完了】未選択、空名、同名、複数rename、不正slug、空白だけの名前を日本語でセクション内拒否し、適切な入力またはエラーへフォーカス。エラー時のみパネルが開き、青いセクションoutlineなし、修正後の再送信成功、データ不変を確認。

#### MT-026 [High] 統合先未選択・自己統合・taxonomy跨ぎ

- 対象機能: 統合検証
- 目的: 無効なdestinationをクライアントとサーバーの両方で拒否する。
- 事前条件: sourceを1件選択。DevToolsでフォームDOMを一時編集できる。
- 操作手順: (1) 統合先未選択で追加する。(2) 通常UIでsourceが候補から除外されることを確認する。(3) DOMのdestination valueをsource自身の`term_id:term_taxonomy_id`へ変更して送信する。(4) タグ画面でcategoryの値へ変更して送信する。
- 期待結果: 未選択、自己統合、taxonomy跨ぎをすべて日本語エラーで拒否し、計画とデータを変更しない。内部例外やID中心の説明を画面へ出さない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: DOM改変は実ブラウザでのサーバー側検証確認に限る。 【2026-09-20実施】統合先未選択、自己統合、taxonomy境界をサーバー側拒否まで確認。

#### MT-027 [Critical] デフォルトカテゴリー・使用中termの削除拒否

- 対象機能: 削除検証
- 目的: 安全でない削除を計画段階で拒否する。
- 事前条件: 既定カテゴリー、PUB、DRAFT、BOTH、EXCLUDED termを用意。
- 操作手順: (1) 既定カテゴリーを選び削除を追加する。(2) 公開使用中カテゴリー／タグで試す。(3) 下書きだけ、固定ページだけで使用するtermで試す。(4) 未使用termとの混合選択でも試す。
- 期待結果: すべて拒否される。混合選択でも安全なtermだけを勝手に計画せず、既定カテゴリー・使用中termは残る。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-a/
- 備考: 全relationship判定を確認するCriticalケース。 【2026-09-26完了】既定カテゴリー、公開・下書き・非公開・固定ページで使用中のcategory/tag、未使用termとの混合をすべて拒否し、term・relationship不変、Item/Journal 0を確認。

#### MT-028 [High] 重複・矛盾・カテゴリー子孫方向の計画拒否

- 対象機能: 計画整合性
- 目的: 同一termへの重複／矛盾操作と循環階層を拒否する。
- 事前条件: 既存draftと`MT-CAT-PARENT`／`CHILD`を用意。
- 操作手順: (1) 同じ名称変更を二重追加する。(2) 同じtermへrename後、mergeまたはdeleteを追加する。(3) PARENTをCHILDへ統合する。(4) 必要に応じてタブを跨いで戻る。
- 期待結果: 重複・矛盾は計画へ追加されず、カテゴリーを子孫へ統合できない。既存の有効なdraftは保持される。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/high-completion/evidence/group-c/results.json
- 備考: 【2026-09-26完了】rename重複、rename＋merge、rename＋delete、merge＋delete、自己統合、taxonomy跨ぎ、親から子孫への統合をすべて拒否。有効な既存draftを保持し、データ変更と内部情報露出がないことを確認。

#### MT-029 [Critical] 消失したtermを含む計画

- 対象機能: 計画再検証
- 目的: draft作成後に対象termが別画面で削除されても誤対象へ実行しない。
- 事前条件: 未使用termのrenameまたはdelete draftを作成済み。
- 操作手順: (1) WordPressの別タブから対象termを削除する。(2) 操作計画を再表示する。(3) プレビューを試す。(4)表示名とエラーを確認する。
- 期待結果: 存在しないtermを検出し、プレビュー／実行を拒否する。別termへ誤適用せず、内部ID、SQL、例外を露出しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-advanced-workflows.json
- 備考: 【2026-09-20実施】計画後にtermを消失させ、古い計画が実行されないことを確認。

### D. プレビューモーダル

#### MT-030 [Critical] 操作計画からだけ開くモーダル

- 対象機能: プレビュー表示
- 目的: プレビューが常設表示や自動再表示ではなく、明示操作でだけ開くことを確認する。
- 事前条件: 有効なdraftを1件以上用意。
- 操作手順: (1) 操作計画を通常表示し、ページ内プレビューがないことを確認する。(2) 「変更内容を確認」を押す。(3) モーダルを閉じる。(4) タブ移動、戻る、再読み込みを行う。(5) 実行完了後にも同様に行う。
- 期待結果: 押した直後だけモーダルが開き、閉じた後、タブ移動、再描画、再読み込み、実行完了後に勝手に再表示されない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: `previewed`状態と表示意思を分離する要件。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-031 [High] プレビュー内容の最小性と正確性

- 対象機能: プレビュー内容
- 目的: 利用者が実行判断に必要な情報だけを正確に確認できることを検証する。
- 事前条件: rename、slug変更、merge、delete、保持予定sourceを含む計画を用意。
- 操作手順: 各項目について、処理方法、変更対象、変更後、影響する公開済み投稿数、削除／保持予定、警告／エラーをベースラインと比較する。
- 期待結果: 数値と名称が正しく、slugは変更時だけ、警告・エラー・削除／保持は0件なら不要表示されない。ハッシュ、fingerprint、Operation ID、term ID、内部statusを表示しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/high-completion/evidence/group-c/results.json、MT-031-preview.png
- 備考: 【2026-09-26完了】rename、明示slug変更、merge、delete、子カテゴリーを持つsource保持を同一プレビューで照合。名称・件数・削除／保持理由は正しく、未変更slug、0件の警告／エラー、hash、fingerprint、Operation／term ID、内部statusは非表示。キャンセル後のterm／relationship不変も確認。

#### MT-032 [High] 対象投稿一覧の遅延表示

- 対象機能: プレビュー詳細
- 目的: 投稿タイトルを初期DOMへ大量描画せず、展開時だけ取得することを確認する。
- 事前条件: 複数投稿に影響するmerge計画、Network記録開始。
- 操作手順: (1) プレビュー直後のDOMとNetworkを確認する。(2) 「対象投稿を確認」を展開する。(3) タイトル一覧と件数を照合する。(4) 閉じて再展開する。
- 期待結果: 初期DOMに投稿タイトル全件がなく、展開時に認証済みAjaxを1回だけ行い、公開済み標準投稿のタイトルだけを表示する。再展開で重複しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 【2026-09-20実施】投稿一覧が初期非表示で、展開時にAjax取得されることを確認。

#### MT-033 [Critical] キャンセル、閉じる、Esc、背景クリック

- 対象機能: モーダル終了
- 目的: 4つの終了手段が共通の非破壊処理を使うことを確認する。
- 事前条件: 検索条件、一覧ページ、選択、入力、背景スクロール位置を識別できる状態でプレビューを開く。
- 操作手順: 「キャンセル」、右上閉じる、Esc、背景クリックを別々に試し、毎回再度プレビューを開く。
- 期待結果: いずれも送信、遷移、再読み込み、計画破棄、状態変更、スクロール移動をせず閉じる。検索・ページ・入力・選択を維持し、起点ボタンへフォーカスが戻る。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-retest-rc2/evidence/results-core-workflows.json、MT-033-after-cancel.png、MT-033-after-close.png、MT-033-after-escape.png、MT-033-after-backdrop.png、MT-033-focus.json、MT-033-fallback.json
- 備考: 【2026-09-20再試験】新RC ZIPのChromiumで、4つの終了手段すべてが非破壊で閉じ、Ajax再描画後の現在DOMにある「変更内容を確認」へフォーカス復帰した。

#### MT-034 [Critical] モーダルの主要ボタンと二重クリック防止

- 対象機能: 実行UI
- 目的: フッター操作が一意で、実行を多重開始しないことを確認する。
- 事前条件: 有効な複数Item計画、Networkを低速化。
- 操作手順: (1) フッターを確認する。(2) 「実行」を素早く連打、Enter連打、ダブルクリックする。(3) Network要求数、履歴、変更ジャーナルを確認する。
- 期待結果: フッターの主要操作は「キャンセル」「実行」だけ。閉じるはヘッダーにある。最初の実行で操作が無効化／ロックされ、同じ変更、Item、Journalを重複生成しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-c/
- 備考: 【2026-09-26完了】連続Enter、ダブルクリック、別タブ同時操作でもOperation 1件、各Item attempt 1、Journal一意を確認。

#### MT-035 [High] モーダルのフォーカストラップと背景非操作

- 対象機能: アクセシビリティ
- 目的: キーボードフォーカスと背景操作をモーダル内へ制限する。
- 事前条件: プレビューを開く。
- 操作手順: (1) 初期フォーカスを確認する。(2) Tabを末尾まで、Shift+Tabを先頭まで繰り返す。(3) 背景のリンク・入力をマウスとキーボードで操作しようとする。(4) スクリーンリーダーのダイアログ名を確認する。
- 期待結果: `role=dialog`、`aria-modal=true`、見出し名が読み上げられ、フォーカスはモーダル内を循環する。背景は誤操作・スクロールできない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/high-completion/evidence/group-d/results.json、MT-035-undo-result.png
- 備考: 【2026-09-26完了】preview／Undoの両モーダルで`role=dialog`、`aria-modal=true`、見出し参照、初期フォーカス、Tab／Shift+Tab循環、背景クリック非操作、body scroll lock、Esc終了と起点へのフォーカス復帰を確認。実行中／Undo中はEscと背景クリックで閉じず、二重操作できないことも確認。

#### MT-036 [Critical] 実行中モーダルのロックと進捗

- 対象機能: 実行中UI
- 目的: 自動バッチ中の誤終了を防ぎ、進捗を理解できることを確認する。
- 事前条件: 10件超の処理、Slow 3G等で各requestを観察可能。
- 操作手順: 実行開始後、キャンセル、閉じる、Esc、背景クリックを試し、本文、`aria-live`、ボタン状態を観察する。
- 期待結果: 実行中は閉じられず二重実行できない。本文に日本語の全体、完了、未処理、失敗、スキップ、状態が更新され、処理ごとの利用者クリックを要求しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: 修正後 build/manual-test/critical-fix-retest/evidence/group-c/MT-036-running.png、MT-036-after.json、network.json、console.txt／修正前 build/manual-test/critical-completion/evidence/group-c/MT-036-failure.png、network.json
- 備考: 【2026-09-26修正前失敗】ロック等は成功したが「未処理」「スキップ」が欠落しCC-001とした。【同日再試験成功】全体・完了・未処理・失敗・スキップ・現在の状態を実行中の`aria-live`と終端直後に日本語表示。自動batch、閉鎖・二重実行拒否、Item 15／Journal 15の一意性も確認し、CC-001を解消。

### E. 実行、一括処理、データ境界

#### MT-037 [Critical] 名称変更の実行

- 対象機能: 実行
- 目的: 名称だけを変更し、slugと全relationshipを保持する。
- 事前条件: MT-017の計画、変更前値を記録。
- 操作手順: プレビューから実行し、完了表示、term編集画面、関連投稿、履歴を確認する。
- 期待結果: nameだけが新値、slugと親・description・全assignmentは不変。成功履歴が1件記録される。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: slug明示変更版もMT-018の計画で繰り返す。 【2026-09-20実施】名称変更を実行し、slugとrelationshipが不変であることを確認。

#### MT-038 [Critical] 複数カテゴリー統合の一括実行

- 対象機能: カテゴリー統合
- 目的: 複数sourceを1回の利用者操作で同一destinationへ安全に統合する。
- 事前条件: `MT-CAT-MERGE-A/B/TARGET`の計画、PUB-01～03の初期assignment記録。
- 操作手順: 1つのプレビューで「実行」を1回押し、完了まで待つ。投稿とtermの状態を確認する。
- 期待結果: 対象公開投稿からA/Bが外れTARGETが1回だけ付く。TARGET付与済み投稿に重複せず、UNRELATEDと他taxonomyは不変。安全なsourceだけ削除される。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-a/MT-038-*
- 備考: 【2026-09-26完了】全対象assignmentを厳密比較し、destination重複なし、無関係term・別taxonomy不変、source削除、Item/Journal一意を確認。

#### MT-039 [Critical] 複数タグ統合の一括実行

- 対象機能: タグ統合
- 目的: 複数source、共有投稿、既存destinationを正しく扱う。
- 事前条件: `MT-TAG-MERGE-A/B/TARGET`の計画。
- 操作手順: 「実行」を1回押し、完了後にPUB-01～03とterm一覧、履歴を確認する。
- 期待結果: A/Bが公開投稿から外れTARGETが追加される。PUB-01の既存TARGETを壊さず重複しない。無関係タグは残る。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-a/MT-039-*
- 備考: 【2026-09-26完了】全対象assignmentを厳密比較し、destination重複なし、無関係term・別taxonomy不変、source削除、Item/Journal一意を確認。

#### MT-040 [Critical] 複数カテゴリー削除の一括実行

- 対象機能: カテゴリー削除
- 目的: 10件超の完全未使用カテゴリーを利用者の1回の実行で削除する。
- 事前条件: MT-021の12件計画。
- 操作手順: プレビュー対象12件と0 relationshipを再確認し、「実行」を1回押す。Networkの複数バッチと最終term一覧を確認する。
- 期待結果: 内部バッチが分かれても追加クリックなしで12件すべて削除され、completedになる。既定カテゴリーと非対象カテゴリーは不変。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-041 [Critical] 複数タグ削除の一括実行

- 対象機能: タグ削除
- 目的: 10件超の完全未使用タグを利用者の1回の実行で削除する。
- 事前条件: MT-022の15件計画。
- 操作手順: プレビュー後、「実行」を1回押し、進捗と最終一覧・履歴を確認する。
- 期待結果: 15件すべてが追加操作なしで削除され、Itemと履歴の件数が一致する。使用中タグは不変。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-core-workflows.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-042 [Critical] 複数種類・両taxonomy計画の1回実行

- 対象機能: 一括実行
- 目的: rename、merge、deleteとcategory／post_tagを、可能な範囲で1回の明示操作から順次処理する。
- 事前条件: 互いに矛盾しない複数種のcategory・tag計画を用意。
- 操作手順: 操作計画でまとめてプレビューし、「実行」を1回押す。以後ボタンを押さず終端まで待つ。
- 期待結果: 開始前に全Operationを検証・ロックし、taxonomy別結果を保ったまま全Itemを自動継続する。片方の結果が他方を成功表示で上書きしない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-advanced-workflows.json
- 備考: 【2026-09-20実施】カテゴリー名称・slug変更とタグ名称変更を1回の実行で完了。

#### MT-043 [Critical] 対象外投稿とsource保持

- 対象機能: 変更範囲
- 目的: relationship変更を公開済み標準投稿だけに限定し、対象外使用中sourceを削除しない。
- 事前条件: `MT-CAT/TAG-EXCLUDED`を公開、下書き、非公開、予約、承認待ち、ゴミ箱、固定ページへ付与。
- 操作手順: EXCLUDEDを既存destinationへ統合実行し、各オブジェクトの前後assignmentとsource term存在を比較する。
- 期待結果: 公開postだけ再割り当てされる。下書き等とpageは一切変わらず、そのrelationshipがあるためsource termを保持し、警告と履歴へ記録する。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/recovery/MT-043/
- 備考: カテゴリーとタグで実施する。 【2026-09-20回復試験】RC ZIPの独立環境で両taxonomyを1回の実行から処理した。公開済み標準投稿だけがdestinationへ移り、下書き・非公開・予約・承認待ち・ゴミ箱・固定ページ・対象外投稿タイプのassignmentは正規化JSONで不変だった。無関係assignmentと両source termを保持し、プレビューの保持表示、completed、Item・Journal・relationship重複なし、予期しないConsole・Network・PHPエラーなしを確認した。

#### MT-044 [Critical] 子カテゴリーを持つsourceの保持

- 対象機能: カテゴリー統合
- 目的: 公開投稿を統合してもsourceと既存の親子関係を保持する。
- 事前条件: PARENTにCHILDがあり、PARENTを別categoryへ統合する計画。
- 操作手順: プレビューの保持理由を確認して実行し、PARENT、CHILD.parent、公開投稿assignmentを確認する。
- 期待結果: 公開投稿のassignmentはdestinationへ移るがPARENTは削除されず、CHILDの親も自動変更されない。警告と履歴が理由を示す。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-a/MT-044-*、MT-044-history.png
- 備考: 【2026-09-26完了】公開投稿だけdestinationへ移動し、sourceと子カテゴリーのparentを保持。プレビュー、保存結果、履歴詳細の保持理由を確認。

#### MT-045 [High] 実行完了後の画面状態

- 対象機能: 結果表示
- 目的: 終端結果を日本語で確認でき、古いモーダルを再表示しないことを確認する。
- 事前条件: completed、partial_failed、failedを少なくとも各1回作る（後二者はMT-051/052と共用可）。
- 操作手順: 各終端後にモーダル状態、操作計画メッセージ、履歴、再読み込み、タブ移動を確認する。
- 期待結果: completed／partial_failed／failedを区別し、終端後に実行済みモーダルを自動再表示しない。成功以外を成功文言で表示しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/high-completion/evidence/group-d/results.json、MT-045-completed.png、MT-045-partial-failed.png、MT-045-failed.png、MT-045-*-after.json
- 備考: 【2026-09-26完了】completed、partial_failed（35成功・1失敗・Journal 70）、failed（開始可能Item 0・Journal 0）の3終端を区別して日本語表示・履歴保存。成功以外を成功文言にせず、タブ移動・再読み込みで古いモーダルが再表示されず、失敗時のsource／relationship不変を確認。

### F. 中断、再開、古いプレビュー、競合

#### MT-046 [Critical] バッチ途中のブラウザ更新と再開

- 対象機能: 実行復旧
- 目的: 更新でクライアント処理が切れてもpendingから重複なく再開する。
- 事前条件: 25件以上のmergeまたはdelete、Network低速化。
- 操作手順: (1) 実行開始し、最初の10件バッチ成功をNetworkで確認する。(2) 次バッチ中にブラウザを更新する。(3) 操作計画で「処理を再開」を1回押す。(4) 終端後のassignment、Item、Journalを確認する。
- 期待結果: 実行中Operationが明示表示され、未処理だけを自動継続する。処理済みrelationship、Item、Journalが重複しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/recovery/MT-046/
- 備考: 【2026-09-20回復試験】30件削除の最初の10件完了後、次batch開始時にブラウザ更新した。更新後はモーダルを自動表示せずrunningと「処理を再開」を表示し、明示再開1回からpending 20件だけを自動処理した。最終Item 30件・Journal 30件で重複なし、completed、予期しないエラーなし。

#### MT-047 [Critical] バッチ途中のタブ終了と再開

- 対象機能: 実行復旧
- 目的: タブを閉じてもサーバー進捗を正として再開できることを確認する。
- 事前条件: MT-046相当の処理を新しいフィクスチャで用意。
- 操作手順: 最初のバッチ成功後にタブを閉じ、再ログインまたは新タブで操作計画を開き、「処理を再開」を1回押す。
- 期待結果: 勝手にモーダルを開かず、明示再開から残件だけ完了する。二重適用しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/recovery/MT-047/
- 備考: 【2026-09-20回復試験】MT-046と別環境で30件削除の最初の10件完了後にPlaywright Pageを終了した。新しいPageではモーダルを自動表示せずrunningを確認し、明示再開1回からpending 20件だけを処理した。Item・Journal・relationship重複なし、completed、予期しないエラーなし。

#### MT-048 [Critical] 実行通信の一時失敗と再開

- 対象機能: ネットワーク障害
- 目的: 通信断で成功扱いせず、復旧後に安全に再開する。
- 事前条件: 25件以上の処理、DevTools request blockingまたはOfflineを使用。
- 操作手順: 最初のバッチ後にOfflineへ切り替え、次要求を失敗させる。画面表示を記録し、Onlineへ戻してページを開き、再開する。
- 期待結果: 中断を成功と表示せず、runningと保存済み進捗が残る。再開でpendingだけ処理し、重複変更がない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/recovery/MT-048/
- 備考: 通信障害時のページreloadフォールバックも観察する。 【2026-09-20回復試験】MT-046/047と別環境で、最初の10件完了後の次batchをPlaywright routeでabortした。成功表示せずrunning・pending 20件を保存し、復旧後の明示再開1回でpendingだけを完了した。Item 30件・Journal 30件、重複なし。Consoleの`net::ERR_FAILED`は意図した通信失敗として分類した。

#### MT-049 [Critical] 実行直前のterm名称・slug変更

- 対象機能: 古いプレビュー拒否
- 目的: プレビュー後のtaxonomy状態変更をfingerprintで検出する。
- 事前条件: rename／merge計画のプレビューモーダルを開いたままにする。
- 操作手順: 別の管理者タブでsourceまたはdestinationの名前／slugを変更し、元タブで「実行」を押す。
- 期待結果: 古いプレビューとして実行開始前に拒否し、予定していた変更を1件も適用しない。再確認を促す日本語表示となる。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-b/MT-049A-*、MT-049B-*
- 備考: 【2026-09-26完了】source／destinationのname・slug変更を別試行し、開始前拒否、予定変更0、管理者変更保持、Item/Journal 0を確認。

#### MT-050 [Critical] 実行直前のrelationship変更

- 対象機能: 古いプレビュー拒否
- 目的: 固定対象や削除可否が変わったプレビューを実行しない。
- 事前条件: mergeまたはdeleteプレビューを開く。
- 操作手順: (1) 別タブで対象投稿へsourceを追加／削除する、または削除予定termを下書きへ付与する。(2) 元タブで実行する。(3) 全対象の状態を確認する。
- 期待結果: 開始前競合なら全開始を拒否する。開始後のItem単位競合なら危険なItemを変更せずpartial_failedとし、安全なItemだけ継続する。新たな投稿を固定対象へ勝手に追加しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-b/MT-050A-*、MT-050B-*
- 備考: 【2026-09-26完了】開始前は全拒否、開始後は競合Itemを上書きせず安全Itemだけ継続し`partial_failed`。プレビュー後の新規投稿を固定対象へ含めないことも確認。

#### MT-051 [Critical] 実行直前の対象term削除

- 対象機能: 競合
- 目的: 消失したsource／destinationを別termへ誤適用せず安全停止する。
- 事前条件: mergeプレビューを開く。
- 操作手順: 別タブでsource、別試行でdestinationを削除し、元タブで実行する。
- 期待結果: 実行を拒否または該当Itemをfailedとし、成功表示しない。他のterm・relationshipを変更しない。利用者向けに再実行可否を判断できる説明を出す。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-b/MT-051A-*、MT-051B-*
- 備考: 【2026-09-26完了】source／destination削除を別試行し、開始前拒否、別termへの誤適用なし、Item/Journal 0、成功・内部例外表示なしを確認。

#### MT-052 [Critical] partial_failed／failedとロック競合

- 対象機能: 終端状態／同時実行
- 目的: 一部失敗・全体失敗・同taxonomyロックを成功と誤表示しない。
- 事前条件: 複数Itemを用意し、バッチ間に1対象だけ安全条件を崩せる。別管理者タブも用意。
- 操作手順: (1) 1バッチ後に1termを使用中へ変え、残りを継続する。(2) 全対象が開始不能な計画を試す。(3) 同taxonomy処理を別タブから同時開始する。(4) 結果、履歴、エラー、再開ボタンを確認する。
- 期待結果: 成功と失敗の混在はpartial_failed、成功Itemなしはfailed、ロック競合は安全停止する。終端失敗を自動再実行せず、理由と再実行可否を日本語で判断できる。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: 修正後 build/manual-test/critical-fix-retest/evidence/group-b/MT-052A-*、MT-052B-*、MT-052C-*、results.json／修正前 build/manual-test/critical-completion/evidence/group-b/MT-052A-*、MT-052B-*、MT-052C-*
- 備考: 【2026-09-26修正前失敗】部分競合とロックは成功したが、全Item開始不能が`previewed`に残りCC-002とした。【同日再試験成功】部分競合は`partial_failed`、利用者が実行を受諾した後に全delete対象が開始前安全検証で処理不能となった場合は`failed`、ロック競合は安全停止を確認。全体失敗はItem／Journal 0、term／relationship不変、日本語で計画再作成案内、自動再実行0で、CC-002を解消。一般のstale、nonce、権限、ロック拒否は従来どおり`previewed`を保つ。

### G. 操作履歴とUndo

#### MT-053 [Critical] 完了操作の履歴記録と所有者分離

- 対象機能: 操作履歴
- 目的: 実行開始済み操作だけを現在管理者の履歴へ記録する。
- 事前条件: draft、previewのみ、completed、partial_failed、failed、別管理者の操作を用意。
- 操作手順: 各管理者で操作履歴を開き、日時順、種別、処理、対象件数、変更件数、結果、Undo可否を確認する。
- 期待結果: draftと通常／Undo previewだけの操作、他管理者の操作は表示されない。開始済み操作だけがWordPress設定タイムゾーンで新しい順に表示される。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/undo-completion/MT-053/
- 備考: 【2026-09-25再試験】draft、通常／Undo preview、他管理者操作を除外し、completed、partial_failed、failed、undoing、undoneの日時順、taxonomy、処理、件数、結果、Undo可否、所有者分離、直接URL拒否を確認した。

#### MT-054 [High] 履歴詳細の要約、全ログ遅延表示、折りたたみ

- 対象機能: 履歴ログ
- 目的: 初期HTMLを抑えつつ、明示操作で全ログを確認できることを検証する。
- 事前条件: 6件超、可能なら101件超のJournalと、長い連続文字列を含むterm名の操作を作成。
- 操作手順: (1) 詳細を開き初期ログ行とNetworkを確認する。(2) 「詳しく見る」を押し全ログ取得を待つ。(3) 100件超なら複数Ajaxページを確認する。(4) 「閉じる」で要約へ戻す。(5) 狭幅で長文折返しを確認する。
- 期待結果: 初期はエラー・警告優先で最大5件。展開時だけ100件単位で全ログを取得し、全体／成功／警告／エラー件数が正しい。閉じると要約へ戻り、画面幅を押し広げない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-undo-workflows.json
- 備考: 内部ID、JSON、lock token、hash、SQL、クラス名、stack traceを表示しない。 【2026-09-20実施】初期要約5件以下、明示展開、折りたたみを確認（101件超は任意条件のため未実施）。

#### MT-055 [Critical] 名称変更のUndo

- 対象機能: Undo
- 目的: nameだけを元へ戻し、元操作とUndoを別履歴として保存する。
- 事前条件: MT-037がcompleted、現nameが実行後値と一致。
- 操作手順: 履歴詳細から「変更を元に戻す」を押し、プレビュー、対象、可否を確認して「元に戻す」を1回押す。
- 期待結果: nameが元値へ戻り、変更しなかったslugとrelationshipは不変。元履歴を変更／削除せず、子Undo履歴がundoneとして追加される。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-undo-workflows.json
- 備考: name＋slug変更では各フィールドの競合判定も確認する。 【2026-09-20実施】名称変更Undoで元名称を復元し、子Undo履歴の終端を確認。

#### MT-056 [Critical] 統合のUndoと既存destination保持

- 対象機能: Undo
- 目的: 元々のsource割り当てだけを戻し、元からあったdestinationを外さない。
- 事前条件: MT-039 completed。PUB-01は元からTARGETあり、PUB-02/03はTARGETなし。
- 操作手順: Undoプレビュー後「元に戻す」を1回押し、全投稿のA/B/TARGET/UNRELATEDを初期ベースラインと比較する。
- 期待結果: A/Bは元々持っていた投稿だけへ復元される。元操作が新規追加したTARGETだけが外れ、PUB-01の既存TARGETは残る。UNRELATEDは不変。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/undo-completion/MT-056/
- 備考: sourceが削除済みなら記録属性で安全に再作成し、新ID対応を内部で扱う。 【2026-09-25再試験】全投稿の正規化assignmentを前後比較し、sourceの新ID復元、既存destination、無関係assignment、子Undo Operation 1件、重複なしを確認した。

#### MT-057 [Critical] 複数カテゴリーを含むUndo

- 対象機能: Undo
- 目的: 複数カテゴリーの変更を1回の利用者操作で戻す。
- 事前条件: 複数source統合または複数削除のcompleted操作。
- 操作手順: Undoプレビューで復元term／assignment／削除assignment件数を確認し、「元に戻す」を1回押して終端まで待つ。
- 期待結果: 追加クリックなしで全Itemを処理し、可能なtermと公開投稿assignmentを正しく復元する。親属性も記録どおりである。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/undo-completion/MT-057/
- 備考: 【2026-09-25再試験】12 sourceカテゴリーを1回の操作・3 batchでUndoし、親属性、全assignment、無関係分類を厳密比較して復元と重複なしを確認した。

#### MT-058 [Critical] 複数タグ・30件超Undoの自動継続

- 対象機能: Undo有界バッチ
- 目的: 1回の「元に戻す」から10件バッチを自動継続する。
- 事前条件: `MT-TAG-UNDO-SOURCE`を35公開投稿からTARGETへ統合済み。Undo Itemが30件超になることをプレビューで確認。
- 操作手順: (1) 「元に戻す」を1回だけ押す。(2) Networkで複数の`taxonomy_tidy_undo_batch`を観察する。(3) 進捗、終端、履歴、全投稿を確認する。
- 期待結果: 各requestは最大10件で、`undoing`かつpendingありの間だけ自動要求する。利用者の追加クリックなしでundoneとなり、全対象が初期状態へ戻る。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-undo-workflows.json
- 備考: Phase 8の明示的リリース阻害事項。 【2026-09-20実施】36 Itemを10件以下の複数batchで追加クリックなしに完了。結果JSONの旧判定文言はスクリーンショットと通信回数で反証済み。

#### MT-059 [Critical] Undo前の管理者変更を上書きしない

- 対象機能: Undo競合
- 目的: 元操作後のname、slug、assignment変更を保護する。
- 事前条件: renameまたはmergeのcompleted操作。
- 操作手順: (1) 別画面で変更後nameを別値へ変更してUndoプレビューする。(2) 別試行でsource／destination assignmentを変更してUndoプレビューする。(3) 実行可能な他Itemがある場合はUndoを実行する。
- 期待結果: 競合Itemを上書きせず、完全／一部／不可を区別する。安全なItemだけ戻した場合はundo_partial_failedで、競合理由を日本語表示する。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-undo-workflows.json
- 備考: 【2026-09-20実施】管理者変更後の競合を取り消し不可として表示し、上書きしないことを確認。

#### MT-060 [Critical] 古いUndoプレビューの拒否

- 対象機能: Undo直前再検証
- 目的: Undoプレビュー後の状態変化を開始前に検出する。
- 事前条件: Undoプレビューモーダルを開く。
- 操作手順: 別タブで対象termまたはassignmentを変更し、元タブで「元に戻す」を押す。
- 期待結果: stale previewとして開始せず、1件もUndoしない。再確認を促し、元操作と管理者変更を保持する。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-undo-workflows.json、build/manual-test/undo-completion/MT-061C-4a/
- 備考: 【2026-09-26再試験】プレビュー後・開始前の状態変更をAjax HTTP 409で拒否し、子Undoを`undo_previewed`から進めず、子Item／Journal 0件、管理者変更保持、「もう一度確認」案内を確認した。通常POSTも自動テストで409 mappingを確認した。

#### MT-061 [Critical] Undo途中の通信断、最大3回再試行、再開

- 対象機能: Undo復旧
- 目的: 一時通信失敗を有界再試行し、長期中断後は明示再開できることを確認する。
- 事前条件: MT-058相当の30件超Undo、Network request blocking。
- 操作手順: (1) Undo開始後、1つのbatch requestを一時失敗させて復旧し、要求回数を数える。(2) 別試行ではOfflineを継続し、3回失敗後の停止表示を確認する。(3) Online復旧後、履歴の「取り消しを再開」を1回押す。
- 期待結果: 一時失敗は最大3回まで、成功すれば継続する。3回失敗では中断を成功扱いせず、履歴からpendingだけを自動再開できる。重複Undoしない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/undo-completion/MT-061A/、build/manual-test/undo-completion/MT-061B/、build/manual-test/undo-completion/MT-061C-1/～MT-061C-5/、build/manual-test/undo-completion/MT-061C-4a/、build/manual-test/undo-completion/MT-061C-4b/
- 備考: 【2026-09-26再試験】2026-09-25の旧C-4は最初のbatch成功後に競合を注入したためC-4bに該当し、200で安全なItemを継続して`undo_partial_failed`（成功35、失敗1）となる仕様どおりの結果だった。C-4aを開始前競合として分離し、HTTP 409、request 1回、自動再送0回、子Item／Journal 0件、管理者変更保持を確認した。C-2は明示JSON 403と再読み込み／再ログイン案内へ修正した。A、B、Cの全サブケースを同一RCで再実行し成功した。

##### MT-061A: 一時的な通信失敗

- Undo batch requestの1つを一時的に失敗させ、復旧後に最大3回の範囲で自動再試行して追加操作なしに継続することを確認する。

##### MT-061B: 継続する通信失敗

- 最初のbatch成功後にUndo batch requestを3回連続して失敗させ、無限再試行せず停止することを確認する。通信復旧後は履歴の「取り消しを再開」1回からpending Itemだけを自動継続する。

##### MT-061C: 自動再試行してはいけない応答

MT-061Cは、Undo batchにおいて自動再試行の対象にしてはいけない応答を正しく停止できることの確認である。一時的な通信失敗だけを最大3回の自動再試行対象とし、権限、nonce、ロック、状態またはfingerprint競合、進捗が前進しない応答は自動再試行しない。

各サブケースは30件を超えるUndo対象、`completed`の元Operation、作成済みの子Undo Operationを用意する。C-1、C-2、C-3、C-4b、C-5は最初のbatchを正常完了して`undoing`かつpending Itemが残る状態から開始する。C-4aだけはUndoプレビュー作成後、最初のUndo batchを送る前に競合を作る。サブケースごとに独立した初期状態を使用し、DB状態を使い回さない。通信制御は対象のUndo batch Ajax requestだけに限定する。

- **MT-061C-1 権限不足による403**: Undo batchを実行できない権限状態で1回送信する。サーバー側capability検証で403相当として拒否し、自動再送を0回、対象request合計1回で停止する。成功・完了表示を行わず、内部情報を含まない日本語の権限エラーと再ログインまたは権限確認が必要と判断できる表示を行い、Operation、Item、Journal、assignmentを変更しない。
- **MT-061C-2 不正または期限切れnonce**: Undo batch requestのnonceを無効な値へ変更して1回送信する。サーバー側で拒否し、自動再送を0回、対象request合計1回で停止する。成功・完了表示を行わず、再読み込みまたは再認証が必要と分かる日本語を表示し、新しいnonceを自動取得せず、Operation、Item、Journal、assignmentを変更しない。
- **MT-061C-3 操作ロック競合による409**: 同じ対象Operationに有効なロックを別処理で保持した状態で1回送信する。409相当で拒否し、自動再送を0回、対象request合計1回で停止する。競合側を成功表示せず、別の処理が実行中であることを日本語で表示する。子Undo Operationを追加作成せず、Item、Journal、assignmentを重複変更しない。ロック解除後は既存の子Undo Operationから明示的に再開できる。
- **MT-061C-4a Undo開始前の状態競合**: 事前条件はUndoプレビュー済み、子Undo Operationが`undo_previewed`、子Item／子Journalが0件であること。プレビュー後、「元に戻す」を押す前に別の管理者画面からsource／destination termの値または対象relationshipを変更し、保存済みhash／fingerprint／実行対象と現在状態を不一致にする。その後「元に戻す」を1回押す。HTTP 409、対象request 1回、自動再送0回で拒否し、子Undo Operationを`undo_previewed`から進めず、term、relationship、Operation Item、Journalを1件も変更しない。別管理者の変更を保持し、「状態が変わったため開始しなかったので、もう一度確認する」旨を日本語で表示する。証跡として競合注入の時刻と方法、Networkのrequest／409応答、前後の元／子Operation、Item、Journal、term、relationship、投稿assignment、画面エラーを保存する。最終Operation状態は子`undo_previewed`、成功0件、失敗0件とする。
- **MT-061C-4b Undo開始後・バッチ途中の項目競合**: 事前条件は最初のUndo batchが成功し、子Undo Operationが`undoing`、完了Itemとpending Itemの両方があること。次batchを送る前に、pending Itemが対象とするtermまたはrelationshipを別の管理者画面から変更する。その後は通常の自動継続に戻す。各batchはHTTP 200で継続してよく、自動再送は通信再試行として0回とする。競合Itemを上書きせず、競合していないItemだけ処理し、最終Operation状態を`undo_partial_failed`とする。成功件数と失敗件数がItem実数に一致し、競合理由を日本語で表示し、同じItem／Journalを重複生成しない。証跡として競合注入の時刻と対象Item、全Undo batchのrequest／response、前後のOperation、Item、Journal、term、relationship、投稿assignment、最終件数と画面表示を保存する。
- **MT-061C-5 正常形式だが進捗が前進しない応答**: Playwrightのroute制御などにより、HTTP成功、現行API仕様に適合するJSON、対象と整合するOperation IDと状態、前回と同じcompleted・pending件数とcursorまたは進捗位置、新しいItem完了・Journal追加なしの応答を1回だけ返す。クライアントが非進捗を検出し、通信障害用の再試行に入らず、自動再送を0回、対象request合計1回で停止する。成功・完了表示を行わず、継続できなかったことを日本語で表示し、Operation、Item、Journal、assignmentを重複変更しない。ページ再読み込みまたは履歴から状態を再確認できる。

各サブケースでUndo batch requestとHeartbeat、履歴取得等を分けて記録する。対象Undo batch requestは最初の拒否または非進捗request 1回、同じ条件の自動再送 0回、合計1回であることを確認する。拒否requestの前後で元Operation、子Undo Operation、status、Item数と各status、Journal数、term、relationship、投稿ごとのassignmentを比較し、最初に正常完了したbatch以外の変更がないことを確認する。

MT-061C-1、C-2、C-3、C-4a、C-4b、C-5の6サブケースがすべて成功した場合だけMT-061Cを成功とする。1件でも失敗した場合は失敗、失敗がなく1件でも実施できない場合は保留とし、成功したサブケースと未確認のサブケースを分けて記録する。MT-061全体はMT-061A、MT-061B、MT-061Cの結果を合わせて判定する。

#### MT-062 [Critical] Undo途中の更新・タブ終了と再開

- 対象機能: Undo復旧
- 目的: クライアント終了後もサーバー進捗から安全に再開する。
- 事前条件: 30件超Undo。
- 操作手順: 最初の10件完了後、(a)ブラウザ更新、別試行で(b)タブ終了する。履歴でundoing子Operationを開き、「取り消しを再開」を1回押す。
- 期待結果: pendingだけを処理し、既に戻したItemやJournalを重複させない。再開後も内部バッチごとのボタンを要求しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/undo-completion/MT-062A/、build/manual-test/undo-completion/MT-062B/
- 備考: 【2026-09-25再試験】別々の独立環境で最初の10件後にブラウザ更新（A）とPage終了（B）を実施。undoing・pending 26件を保持し、「取り消しを再開」1回から残件だけを処理してundoneへ到達した。全assignmentは元操作前と一致し、Item・Journal重複なし。

#### MT-063 [Critical] Undoの重複開始防止

- 対象機能: Undo冪等性／ロック
- 目的: 同一元操作へのUndoを二重実行できないことを確認する。
- 事前条件: Undo可能なcompleted操作、2ブラウザタブ。
- 操作手順: 両タブでUndoプレビューを開き、ほぼ同時に「元に戻す」を押す。完了後、再度元履歴からUndoを試す。
- 期待結果: 1つだけ開始し、他方は既開始・lock・staleとして拒否する。子Operation、term、assignment、Journalが重複しない。完了後は再Undo不可となる。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/undo-completion/MT-063-firefox/、build/manual-test/undo-completion/MT-063-webkit/
- 備考: 【2026-09-25ブラウザ差分試験】Firefox 155とWebKit 26.6の独立環境で、同時2タブから子Operation 1件、Undo実行1回、競合側の安全な日本語拒否、データ・Item・Journal重複なし、完了後の再Undo不可を確認した。

#### MT-064 [High] Undoモーダルと結果のアクセシビリティ

- 対象機能: Undo UI
- 目的: Undoプレビュー／進捗／結果をキーボードと支援技術で操作・理解できることを確認する。
- 事前条件: Undo可能操作。
- 操作手順: キーボードだけで「変更を元に戻す」→モーダル→キャンセル／再表示→「元に戻す」と進み、Esc、背景、フォーカス循環、`aria-live`を確認する。
- 期待結果: 実行前はキャンセル可能、undoing中は閉じられず、進捗が通知され、終了時は安全な画面へ戻る。完全、一部、失敗の表現が色だけに依存しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-retest-rc2/evidence/results-undo-workflows.json、MT-064-before.png、MT-064-running.png、MT-064-result.png
- 備考: 【2026-09-20再試験】新RC ZIPのChromiumで、Undo中のロック、aria-live進捗、終端後の「取り消し結果」、件数表示、閉じるだけのfooter、再送防止、履歴更新、履歴タブへのフォーカス復帰を確認した。

### H. UI、視覚、アクセシビリティ、他画面への影響

#### MT-065 [High] 検索・処理パネルの配置と初期状態

- 対象機能: 管理画面レイアウト
- 目的: 指定された情報階層を確認する。
- 事前条件: カテゴリー／タグ画面を新規表示。
- 操作手順: ページ上から下へ視認し、検索パネル、処理パネル、term一覧の順、両パネルの外観・開閉状態を確認する。
- 期待結果: 両パネルは検索エリア付近にまとまり同じアコーディオン外観で初期閉。処理パネルが一覧の下へ移動していない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json、MT-065-panel-order.png
- 備考: エラー後は処理パネルが開くことは意図した例外。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-066 [High] 処理パネルの整列と視覚的区別

- 対象機能: フォームUI
- 目的: 選択中対象、処理方法、変更内容、検証、操作が理解しやすいことを確認する。
- 事前条件: rename／merge／deleteを順に選択。
- 操作手順: ラジオ、ラベル、入力、統合元・先、削除対象、見出し、境界線、余白をデスクトップ幅で確認する。
- 期待結果: コントロール位置が揃い、統合元と統合先を明確に区別でき、各セクションのグルーピングと余白が自然で、タイトルが直前のborderに接触しない。内部IDを表示しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-advanced-workflows.json、MT-066-action-panel.png
- 備考: 同名カテゴリーだけは親名を使った階層表現で判別し、通常候補とタグに不要な補助表示を出さない。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-067 [High] エラー表示とフォーカス表示の分離

- 対象機能: エラーUI／アクセシビリティ
- 目的: エラー時の不自然な青枠を避けつつ、通常のキーボードフォーカスを保持する。
- 事前条件: MT-025/026の入力エラーを発生させる。
- 操作手順: (1) エラー発生前後のセクション外枠を比較する。(2) Tabで各入力、radio、button、link、scroll regionへ移動する。(3) マウスクリック時とも比較する。
- 期待結果: エラーは該当セクション内に日本語・アイコン／文言付きで表示し、色だけに依存しない。セクション全体へ不自然な青outlineを付けない。一方、キーボードフォーカスoutlineは視認できる。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: docs/MANUAL_TEST_REPORT.md「ケース別結果」
- 備考: 2種類のoutline要件を混同しない。 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-068 [Critical] キーボードだけでの主要ワークフロー

- 対象機能: キーボード操作
- 目的: ポインティングデバイスなしで主要操作を完了できることを確認する。
- 事前条件: demoデータ、マウス／トラックパッドを使わない。
- 操作手順: Tab、Shift+Tab、矢印、Space、Enter、Escだけで、タブ移動、パネル開閉、検索、選択、計画追加、計画確認、モーダルキャンセル、再表示、実行、履歴詳細、ログ展開、Undoまで行う。
- 期待結果: フォーカス順が論理的で常に視認でき、全主要操作に到達・実行でき、フォーカス消失や背景への脱出がない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/critical-completion/evidence/group-d/MT-068-*
- 備考: 【2026-09-26完了】4タブ、パネル、検索、選択、radio、計画、preview cancel/reopen/execute、履歴詳細、ログ展開、Undoをキーボードだけで完了。フォーカストラップ・復帰・可視フォーカスとassignment復元を確認。

#### MT-069 [High] 狭幅・拡大表示

- 対象機能: レスポンシブ表示
- 目的: 狭いブラウザ幅や拡大でも操作不能にならないことを確認する。
- 事前条件: 320 CSS px相当、400%、ブラウザ標準拡大200%を個別に試せる。
- 操作手順: 4タブ、両パネル、一覧、操作計画、履歴詳細、モーダル、長いterm名を各幅で表示し、横スクロール領域もキーボード操作する。
- 期待結果: 内容が重なり・切れ・画面外固定にならず、表は明示領域内で操作でき、主要ボタンとモーダル本文／固定footerへ到達できる。
- 実施結果: [ ] 未実施 [ ] 成功 [x] 失敗 [ ] 保留
- 証跡: build/manual-test/high-completion/evidence/group-d/results.json、MT-069-*.png
- 備考: 【2026-09-26失敗】320 CSS pxのカテゴリー／タグ一覧、長いterm名、640 CSS pxの200%相当ではページ全体の横あふれなし。320 CSS pxの操作履歴一覧（400%相当）では、720 px最小幅の表が明示スクロール領域へ閉じず、document幅649 px、ページ全体の横移動329 pxを再現した。表領域自体はキーボードフォーカス可能。headless Chromiumの標準拡大shortcutは倍率を変更せず、実200%確認も未完了。

#### MT-070 [High] スクリーンリーダー基本確認

- 対象機能: 支援技術
- 目的: 見出し、タブ、表、状態、モーダル、進捗、エラーの意味が読み上げで分かることを確認する。
- 事前条件: VoiceOver、NVDA等を1つ以上使用。
- 操作手順: ページ見出しから4タブ、accordion summary、表見出し／sort、checkbox label、入力エラー、preview dialog、progress live region、history logsを移動する。
- 期待結果: 名前、role、状態、現在位置、エラー関連付けが理解でき、閉じる`×`には「閉じる」のラベルがある。視覚だけの内部アイコンは重複読上げしない。
- 実施結果: [ ] 未実施 [ ] 成功 [ ] 失敗 [x] 保留
- 証跡: docs/MANUAL_TEST_HIGH_COMPLETION_REPORT.md「MT-070」
- 備考: 【2026-09-26再試験】Safari 27.0とVoiceOverの存在は確認したが、`safaridriver --enable`は管理者認証を通せず、SafariへのApple Eventsも拒否された。VoiceOverの実読み上げを操作・記録できていないため保留を維持する。Playwright WebKitやDOM検査を代替成功とは扱わない。

#### MT-071 [High] WordPress管理画面・公開側への非干渉

- 対象機能: CSS／JavaScript隔離
- 目的: プラグイン資産が対象画面以外を壊さないことを確認する。
- 事前条件: プラグイン有効化済み、標準テーマ。
- 操作手順: 投稿一覧・編集、カテゴリー、タグ、ツール、ダッシュボード、プロフィール、公開記事を開き、Networkでplugin CSS/JSの読込とレイアウト・Consoleを確認する。
- 期待結果: 管理資産はTaxonomy Tidy画面だけで読み込まれ、他管理画面と公開テーマのレイアウト・操作・Consoleに影響しない。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: build/manual-test/evidence/results-non-mutating.json
- 備考: 【2026-09-20実施】2026-09-20、隔離RC環境の実ブラウザで期待結果を確認。

#### MT-072 [Critical] 全試験中のPHP・JavaScriptエラー監視

- 対象機能: 品質ゲート
- 目的: 主要導線全体で予期しない実行時エラーがないことを確認する。
- 事前条件: `WP_DEBUG=true`、`WP_DEBUG_LOG=true`、Console/Network Preserve log有効。
- 操作手順: 全Critical・High終了後、Console、失敗Network、debug.log、Web/PHPログを開始時刻以降で検索し、Warning、Notice、Deprecated、Fatal、uncaught error、5xxを分類する。
- 期待結果: プラグイン起因のPHPエラー・警告・非推奨、未処理JSエラー、予期しない4xx/5xxが0件。意図した拒否応答はケースIDと対応付けられる。
- 実施結果: [ ] 未実施 [x] 成功 [ ] 失敗 [ ] 保留
- 証跡: 各*-console.txt、各*-network.txt、docs/MANUAL_TEST_REPORT.md「ログ確認」
- 備考: WordPress Core由来と判断する場合も根拠を添えて保留／既知事項にする。 【2026-09-26再確認】最終RCスモークのdebug.logは0行。ブラウザ記録に未処理JSエラー・予期しない5xxなし。MT-060／MT-061C-4aの409とMT-061C-1/C-2の403は意図した拒否。

## 6. Go／No-Go判定表

| 判定条件 | 結果 | 証跡・備考 |
|---|---|---|
| Criticalがすべて成功 | [x] Go [ ] No-Go | Critical成功44件、失敗0件、保留0件。MT-036とMT-052の修正・回帰成功。 |
| Highに未確認または重大な失敗がない | [ ] Go [x] No-Go | High成功25件、失敗1件、保留2件。MT-069失敗、MT-006／070保留。 |
| 配布ZIPで主要操作を確認済み | [x] Go [ ] No-Go | RC ZIPを管理画面から新規導入して確認。 |
| 名称変更、統合、複数削除が成功 | [x] Go [ ] No-Go | MT-038/039を含め、厳密な全assignment比較まで成功。 |
| 複数件を利用者の1回の実行操作で処理できる | [x] Go [ ] No-Go | MT-042成功。 |
| 中断後の再開で重複処理が発生しない | [x] Go [ ] No-Go | MT-046～048、MT-061～062でpending再開と重複なしを確認。 |
| 複数件のUndoが1回の操作で完了する | [x] Go [ ] No-Go | MT-058で36 Itemの自動継続を確認。 |
| 対象外投稿や無関係な分類を変更しない | [x] Go [ ] No-Go | MT-043で両taxonomyと全対象外オブジェクトを正規化JSON比較。 |
| 日本語UIに意図しない英語が混在しない | [x] Go [ ] No-Go | MT-005成功。 |
| PHPエラーとJavaScriptエラーがない | [x] Go [ ] No-Go | MT-072成功。意図したHTTP拒否、通信遮断、ローカルHTTP環境警告を除き、追加試験でも予期しないPHP/JavaScriptエラーなし。 |
| キーボード操作とモーダルの基本アクセシビリティを確認済み | [ ] Go [x] No-Go | MT-033/064/068は成功。スクリーンリーダー実機のMT-070は保留。 |
| Phase 8の既知のリリース阻害事項を解消または正式に受容済み | [ ] Go [x] No-Go | CC-001／CC-002は解消。MT-069の不具合とMT-006／070の未確定事項が残る。 |

### 最終判定

- [ ] **Go**
- [x] **No-Go（失敗1件、保留2件）**

判定日: 2026-09-26

判定者: Codex（実ブラウザ操作支援）

対象ZIP／SHA-256: `taxonomy-tidy-0.1.0.zip` / `528362390fa5183d53fd6e938786351ecc4ce5f71f51a10e637c89bbbacd1610`

未解決Issue: MT-069の狭幅横あふれ、MT-006の保持方針未確定、MT-070の実スクリーンリーダー未実施。詳細は`docs/MANUAL_TEST_HIGH_COMPLETION_REPORT.md`を参照。

Goに変更できるのは、上表をすべて満たし、失敗を修正した場合は影響範囲の回帰試験まで成功した後だけとする。Mediumの失敗を残す場合も、利用者影響、回避策、公開後対応時期を明記して承認を得る。

## 7. 実施集計

| 優先度 | 総数 | 未実施 | 成功 | 失敗 | 保留 |
|---|---:|---:|---:|---:|---:|
| Critical | 44 | 0 | 44 | 0 | 0 |
| High | 28 | 0 | 25 | 1 | 2 |
| Medium | 0 | 0 | 0 | 0 | 0 |
| 合計 | 72 | 0 | 69 | 1 | 2 |

## 8. テスト開始前に必要なもの

- 現行ソースから生成しチェックサムを固定したRC ZIP
- ソースをマウントしない破棄可能なDocker WordPress環境とDB／volumeスナップショット
- WordPress最低対応、現行安定版、MySQL、MariaDBを含む実施環境
- Chrome系、Firefox、Safari/WebKitの実ブラウザとDevTools
- 日本語管理者、全3 capabilityを持つ管理者、capability不足利用者、競合操作用の第2管理者
- `WP_DEBUG`／`WP_DEBUG_LOG`、PHP／Webサーバーログへのアクセス
- demo、large、補助フィクスチャ、30件超Undo用投稿、変更前ベースライン
- Network throttling、Offline、request blockingを行えるブラウザ環境
- VoiceOverまたはNVDA等のスクリーンリーダー
- 失敗記録用Issue番号、スクリーンショット／動画／HAR／ログの安全な保管先

## 9. 現時点の未確認事項と既知のリスク

- MT-033とMT-064は新RC ZIPのChromium再試験で成功した。詳細は`docs/MANUAL_TEST_RETEST_REPORT.md`を参照。
- 現行RC ZIPで名称変更、統合、複数削除、両taxonomy一括実行、履歴、36 Item Undo自動継続に加え、実行／Undoの通信断・更新・タブ終了からのpending再開を確認した。
- MT-063はFirefox/WebKitの既存成功に加え、修正後RCのChromiumでも子Operation 1件、Undo実行1回、データ・Item・Journalの重複なしを確認した。MT-062A/Bも修正後RCで再成功。MT-061CはC-1/C-2/C-3/C-4a/C-4b/C-5の全6条件が成功し、MT-061全体を成功へ更新した。詳細は`docs/MANUAL_TEST_UNDO_COMPLETION_REPORT.md`を参照。
- キーボードのみの全導線、実スクリーンリーダー、200%・400%拡大は保留。320～1280 CSS pxの機械計測は横溢れなし。
- 1,000タグの統合先全件selectは製品判断で許容されたが、今回のRC環境ではlarge seedを投入しておらず、体感性能と操作性は保留。
- RC ZIPは新規環境へ管理画面から導入済み。同版上書き、アンインストール、正式旧版からの更新は保留。
- 過去の正式配布版がないため、実版間アップグレードは未確認。
- アンインストール時の3テーブル保持は現行挙動・下位資料には記載があるが、要件正本での方針確定が必要。
- PHP 8.4＋WordPress 6.6.2のテスト導入時にCore側`E_STRICT`非推奨警告1件の既知記録がある。通常実行でプラグイン起因かを切り分ける。
