# Phase 8 リリースチェックリスト

## 2026-09-27 最終リリース判定

- [x] 手動テスト72件を機械再集計
- [x] Critical 成功44・失敗0・保留0
- [x] High 成功25・失敗1・保留2を確認し、Criticalへの波及がないことを評価
- [x] CC-001、CC-002、MT-061Cを含むCritical関連不具合の修正後結果と証跡を確認
- [x] `make check`成功（PHPCS 64/64、JavaScript lint、PHPUnit 119 tests / 1,472 assertions、Ajax 1 test / 6 assertions）
- [x] 正式Playwright E2E成功（認証setup 1件＋E2E-001〜008、計9件）
- [x] 現行作業ツリーからRC ZIPを再生成
- [x] `unzip -t`、SHA-256照合、配布除外、変更製品ファイルのソース一致を確認
- [x] 現行RC ZIPをソース非マウントの独立環境へ導入
- [x] 影響限定回帰: 名称変更、結果通知、320 CSS px折返し、操作履歴、Undo
- [x] 実行後の再読み込みでプレビューモーダルが自動再表示されない
- [x] slug、無関係assignment、下書き、未操作termが不変
- [x] 重複Item 0、重複Journal 0
- [x] 製品起因PHP Fatal／Warning／Notice／Deprecated 0、未処理JavaScriptエラー 0、予期しないNetworkエラー 0
- [x] 複数カテゴリー／タグ統合、複数削除、一括実行、対象外データ不変の直近実ブラウザ証跡を再確認
- [x] `git diff --check`成功
- [x] Phase 7 `Completed`
- [x] Phase 8 `Completed`
- [x] **Go**

集計: Critical 成功44・失敗0・保留0、High 成功25・失敗1・保留2、全72件 成功69・失敗1・保留2。

対象ZIP: `dist/taxonomy-tidy-0.1.0.zip`

SHA-256: `f79e2f7d300e8e528551c2e9b62baecf2ef4b4f6858d1f250aba93558884793f`

公開後確認: MT-069の狭幅操作履歴、MT-006のアンインストール保持方針、MT-070のVoiceOver／NVDA実読み上げ、Safari実機、実ブラウザ200%拡大。今回の明示基準に従い、これらHighの失敗・保留はCriticalへ波及しないため公開阻害としない。外部公開、WordPress.org申請、commit、push、タグ、GitHub Releaseは実施していない。

## 2026-09-26 残存High 10件実施後

- [x] 現行RC ZIPをソース非マウントの独立4環境へ管理画面から導入
- [x] MT-011／015／025／028／031／035／045成功
- [ ] MT-069 — 320 CSS pxの操作履歴一覧でページ全体が329 px横移動する（公開阻害）
- [ ] MT-006 — 実挙動は保持・再利用に成功したが、アンインストール方針の正本未確定
- [ ] MT-070 — VoiceOver／NVDA実読み上げ未実施
- [ ] Safari実機スモーク — 起動のみ。WebDriver／Apple Events権限不足で操作未確認
- [x] `make check`成功（PHPCS 62/62、JavaScript lint、PHPUnit 119 tests / 1,466 assertions、Ajax PHPUnit 1 test / 6 assertions）
- [x] RC ZIPの`unzip -t`、checksum、現行ソースとの内容照合成功
- [x] 4環境で未処理JS例外、予期しない5xx、PHP Fatal／Warning／Notice／Deprecated 0件
- [x] Critical 成功44・失敗0・保留0
- [ ] Highの失敗・保留を0件にする
- [ ] Go
- [x] **No-Go** — MT-069失敗、MT-006／070保留のため

集計: Critical 成功44・失敗0・保留0、High 成功25・失敗1・保留2、全72件 成功69・失敗1・保留2。

対象ZIP: `dist/taxonomy-tidy-0.1.0.zip`

SHA-256: `528362390fa5183d53fd6e938786351ecc4ce5f71f51a10e637c89bbbacd1610`

Phase 7は`In progress`、Phase 8は`Blocked`を維持する。製品コード修正、コミット、push、GitHub Release、WordPress.org公開は行っていない。詳細は`docs/MANUAL_TEST_HIGH_COMPLETION_REPORT.md`を参照。

以下は今回のHigh完了試験より前のチェック記録として保持する。

## 2026-09-26 CC-001／CC-002修正後RC

- [x] CC-001: 実行中・終端直後の6進捗項目と`aria-live`を実装・検証
- [x] CC-002: 受諾後の全delete対象開始不能を`failed`終端とし、Item／Journal 0・データ不変を検証
- [x] 一般のstale、nonce、権限、ロック拒否が`previewed`を保つ回帰を検証
- [x] `make check`成功（PHPCS 62/62、JavaScript lint、PHPUnit 119 tests / 1,466 assertions、Ajax PHPUnit 1 test / 6 assertions）
- [x] 新RC ZIPの`unzip -t`／checksum照合成功
- [x] MT-036／052のChromium実ブラウザ再試験成功
- [x] 限定回帰MT-034／045／050成功
- [x] 独立2環境の`debug.log` 0 byte、未処理JSエラー・予期しない4xx/5xx 0件
- [x] Critical 成功44・失敗0・保留0
- [ ] High保留10件を完了
- [ ] Go
- [x] **No-Go** — High保留10件が残るため

集計: Critical 成功44・失敗0・保留0、High 成功18・失敗0・保留10、全72件 成功62・失敗0・保留10。

対象ZIP: `dist/taxonomy-tidy-0.1.0.zip`

SHA-256: `528362390fa5183d53fd6e938786351ecc4ce5f71f51a10e637c89bbbacd1610`

Phase 7は`In progress`、Phase 8は`Blocked`を維持する。コミット、push、GitHub Release、WordPress.org公開は行っていない。詳細は`docs/MANUAL_TEST_CRITICAL_COMPLETION_REPORT.md`を参照。

以下は修正前またはそれ以前のチェック記録として保持する。

## 2026-09-26 残存Critical完了後

- [x] 現行RC ZIPをソース非マウントの独立環境へ管理画面から導入
- [x] 残存Critical 11件をChromium実ブラウザで実施（成功9、失敗2、保留0）
- [x] MT-027、034、038、039、044、049、050、051、068成功
- [ ] MT-036 — 実行中進捗に「未処理」「スキップ」がない（公開阻害CC-001）
- [ ] MT-052 — 全Item開始不能時にOperationが`failed`にならない（公開阻害CC-002）
- [x] 4環境の`debug.log` 0 byte、未処理JSエラー・予期しない4xx/5xx 0件
- [x] Critical保留0件
- [ ] High保留10件を完了
- [ ] Go
- [x] **No-Go** — Critical失敗2件、High保留10件が残るため

集計: Critical 成功42・失敗2・保留0、High 成功18・失敗0・保留10、全72件 成功60・失敗2・保留10。

対象ZIP: `dist/taxonomy-tidy-0.1.0.zip`

SHA-256: `48025c0f330f5ed829e103448c92a5e25aea9cad08d72f40f030174887d798e2`

Phase 7は`In progress`、Phase 8は`Blocked`を維持する。詳細は`docs/MANUAL_TEST_CRITICAL_COMPLETION_REPORT.md`を参照。

## 2026-09-26 MT-061修正後RC

- [x] 不正nonceがJSON 403、非再試行、再読み込み／再ログイン案内となる
- [x] Undo開始前の状態競合が409で停止し、子Item／Journalを作らず管理者変更を保持する
- [x] Undo開始後の項目競合は安全なItemを継続し、競合Itemだけ失敗、`undo_partial_failed`となる
- [x] MT-061A/B/Cの全条件をソース未マウントの独立RC環境で確認
- [x] MT-058～060、MT-062A/B、MT-063 ChromiumのUndo回帰成功
- [x] Firefox／WebKitのMT-063は変更影響外であり、2026-09-25の成功証跡を確認
- [x] `make check`成功（通常114 tests / 1,421 assertions、Ajax 1 test / 6 assertions）
- [x] `make dist`、`unzip -t`、SHA-256照合成功
- [x] 新規WordPress環境で名称変更、複数source統合、複数タグ／カテゴリー削除、複数Item Undoをスモーク
- [x] 予期しないConsole／Network／PHPエラー0件、最終`debug.log` 0行
- [ ] Go
- [x] **No-Go** — Critical保留11件、High保留10件が残るため

対象ZIP: `dist/taxonomy-tidy-0.1.0.zip`

SHA-256: `48025c0f330f5ed829e103448c92a5e25aea9cad08d72f40f030174887d798e2`

Phase 7は`In progress`、Phase 8は`Blocked`を維持する。以下は初回Phase 8検証時点のチェックリストとして保持する。

実施日: 2026-09-19（Asia/Tokyo）  
対象: Taxonomy Tidy 0.1.0 / Git `65828f028fc16483128180f4325dc308cebbdbb1` からの作業ツリー

## 自動検証

- [x] `make check` 成功
  - PHPCS: 60 / 60 files
  - JavaScript lint: 成功
  - PHPUnit / WordPress統合テスト: 104 tests, 1,343 assertions
- [x] PHP 8.2で統合テスト成功
- [x] PHP 8.3で統合テスト成功（99 tests, 1,313 assertions）
- [x] PHP 8.4で統合テスト成功（99 tests, 1,313 assertions）
  - WordPress 6.6.2のCoreテスト導入コードから`E_STRICT`非推奨警告が1件出た。プラグインのテスト失敗はない。
- [x] PHP構文チェック成功（本体、テスト、シードツール）
- [x] `composer validate --strict --no-check-publish` 成功
- [x] `composer audit --locked` 成功（既知の脆弱性なし）
- [x] Composer依存ライセンス確認
- [x] JSON構文チェック成功
- [x] XML構文チェック成功
- [x] シェルスクリプト構文チェック成功
- [x] 開発用・リリーススモーク用Docker Compose設定チェック成功
- [x] `git diff --check` 成功

### Undo自動継続・履歴ログ改善

- [x] Undoのサーバー側バッチサイズ10件、pending再開、60秒ロック、項目・Journalの冪等性を維持
- [x] 30件を10件ずつ3バッチで`undone`まで処理する統合テスト成功
- [x] クライアントが`has_more=true`かつ`status=undoing`の間だけ次バッチを要求し、終端状態と進捗停滞で停止する静的テスト成功
- [x] 一時的な通信失敗の最大3回再試行と即時ボタン無効化の静的テスト成功
- [x] ログ初期表示5件、エラー・警告優先、総数・成功・警告・失敗件数、100件単位ページ取得の統合テスト成功
- [x] ログ詳細取得で権限、nonce、Operation所有者、Undo親子関係をサーバー側検証
- [ ] 実ブラウザで30件以上のUndo自動継続、通信断、中断後の1回再開、ログ展開・折りたたみ、狭幅、読み上げを確認
  - 今回も利用可能な実ブラウザ実行環境がないため未検証。Phase 8のGo条件には含めない。

## 対応環境

- [x] WordPress 6.6.2 / PHP 8.2 / MySQL 8.0.46
- [x] WordPress 7.1.1 / PHP 8.4.25 / MySQL 8.0
- [x] WordPress 6.6.2 / PHP 8.2 / MariaDB 10.11.19
- [x] プラグインヘッダー、PHP定数、README、CHANGELOG、ZIP名のバージョンが`0.1.0`で一致
- [x] スキーマバージョン`1`を作成・保存

## インストール・ライフサイクル

- [x] 既存環境と別のComposeプロジェクト・DB・ボリュームを使用
- [x] 配布ZIPからクリーンインストール・有効化成功
- [x] 3テーブル作成とスキーマバージョン保存を確認
- [x] 「ツール」メニュー登録と管理者アクセス判定を確認
- [x] 3権限の一部しか持たない利用者を拒否
- [x] CSS・JavaScriptがHTTP 200
- [x] 未認証の管理画面URLがログイン画面へリダイレクト
- [x] 無効化・再有効化成功、既存Operationを保持
- [x] アンインストール・ZIP再インストール成功
  - Undoと監査に必要な3テーブルは安全側で保持する仕様。
- [x] WordPress 6.6.2と7.1.1/PHP 8.4で管理画面描画後の`debug.log`が空

## スキーマ・データ保持

- [x] `dbDelta()`を2回再適用してもOperation、Operation Item、Change Journalを保持
- [x] 再有効化後もOperationとスキーマバージョンを保持
- [x] 重複テーブルを作成しない
- [ ] 実際の過去リリース物からの更新確認
  - 過去の正式配布物が存在しないため対象なし。現行開発スキーマへの再適用だけを確認した。
- [ ] スキーマ更新途中の強制障害試験
  - スキーマバージョン1からの移行処理が存在しないため未実施。

## 大量データ・性能

- [x] 再現可能なシードで100カテゴリー、1,000タグ、320公開投稿と除外ステータスを生成
- [x] 一覧クエリは20/50/100件上限と数値ページを維持
- [x] 一覧、検索、並べ替え、完全未使用絞り込みは各2〜3クエリ、最大約21ms
- [x] 4タブをサーバー描画できる
- [x] 一覧テーブルは1,000タグを全件描画せず、20件のサーバー側ページネーションを維持
- [x] 統合先候補は操作性を優先し、同一taxonomyの1,000件を入力不要の選択欄へ1回だけ描画
  - 現行: HTMLは132,661 bytes（約129.6KiB）、統合先候補1,000件、一覧行20件。
  - 候補には名前だけを表示し、slug、description、relationship、重複候補データは含めない。内部値には安定IDを保持する。
  - 約328KBだった旧`datalist`＋`template`二重描画より小さいが、候補数に比例してHTMLが増えることは製品判断で許容する既知の制限とする。
  - 将来的には、入力不要の候補閲覧性を維持したまま初期転送量を抑える改善を検討する。

## 配布物

- [x] `make dist`で本番用Composer autoloadを再生成
- [x] ZIP直下に`taxonomy-tidy/`が1つだけ存在
- [x] メインファイル、`src/`、`assets/`、`languages/`、`vendor/autoload.php`、README、CHANGELOG、LICENSEを含む
- [x] tests、tools、Docker、docs、`.env*`、Git、Node、開発用Composer依存を除外
- [x] `unzip -t`成功
- [x] 同一ツリーから2回生成したSHA-256が一致
- [x] 秘密情報に該当するファイルを含まない
- [x] SHA-256を生成
  - 現行の統合先選択UIを含むZIP SHA-256: `50da61d6009aae7d2ed9e67be7cbfdefcf78eb65148354e4288962f9943418ae`

## 手動受け入れ・画面

- [x] WP-CLI管理者コンテキストでカテゴリー、タグ、操作計画、履歴をサーバー描画
- [x] 大量データで初期一覧行が20件以内、アコーディオン初期閉を確認
- [ ] 実ブラウザでの4タブ、狭幅、キーボード、フォーカス、Esc、背景クリック、スクリーンリーダー確認
  - 利用可能なブラウザ実行環境がなく未検証。Phase 7もこの理由で`In progress`。
- [ ] リリース候補ZIPを使った実ブラウザでの名称変更、統合、複数削除、複数計画実行、中断・再開、履歴、Undo
  - 自動統合テストは成功しているが、Phase 8の実ブラウザ手動試験は未実施。
- [ ] 「元に戻す」1回で30件以上が10件ずつ自動継続し、進捗更新、終端停止、履歴更新を確認
- [ ] 履歴ログが初期5件以内で、`詳しく見る`と`閉じる`、長い連続文字列の折り返し、内部情報非表示を確認
- [ ] 現行の統合先選択UIを含むZIPを新規環境へ再インストールして実ブラウザ確認
  - サーバー描画と自動テストは成功したが、現行UIの実ブラウザ確認は未実施。

## 判定

- [ ] Go
- [x] **No-Go**

統合先候補の全件表示は、入力なしで全候補を確認できる操作性を優先した製品判断として許容する。一覧テーブルのページネーションは維持している。ただし、現行UIを含む主要導線の実ブラウザ手動試験が未完了であるため、判定はNo-Goを維持する。外部公開、WordPress.org公開、GitHub Release作成、外部アップロードは行っていない。
