# Taxonomy Tidy 0.1.0 Phase 8 テストレポート

## 2026-09-26 残存High 10件 実ブラウザ試験

現行RC ZIPをソース非マウントの4つの独立Docker環境へ管理画面から導入し、保留だったHigh 10件をChrome for Testing / Chromium 153で実施した。結果は成功7件、失敗1件、保留2件。Criticalは成功44・失敗0・保留0、Highは成功25・失敗1・保留2、全72件は成功69・失敗1・保留2となった。

- 成功: MT-011、015、025、028、031、035、045。
- 失敗: MT-069。320 CSS pxの操作履歴一覧で、720 px最小幅の表が明示スクロール領域へ閉じず、document幅649 px、ページ全体の横移動329 pxを再現した。
- 保留: MT-006。無効化、再有効化、同版上書き、アンインストール、再導入の実挙動は3テーブル、schema option、履歴を保持して成功したが、上位正本`REQUIREMENTS.md`に保持・履歴再利用・利用者告知方針がない。
- 保留: MT-070。Safari 27.0／VoiceOverは存在するが、Safari WebDriverの有効化とApple Events／アクセシビリティ操作権限を得られず、実読み上げを確認できなかった。
- ログ: 未処理JavaScript例外、request failure、予期しない5xx、PHP Fatal／Warning／Notice／Deprecatedは0件。4環境とも`WP_DEBUG_LOG`は有効だが`debug.log`は生成されなかった。
- 自動検証: PHPCS 62/62、JavaScript lint、PHPUnit 119 tests / 1,466 assertions、Ajax PHPUnit 1 test / 6 assertionsを含む`make check`が成功。
- RC: `dist/taxonomy-tidy-0.1.0.zip`、SHA-256 `528362390fa5183d53fd6e938786351ecc4ce5f71f51a10e637c89bbbacd1610`。`unzip -t`、checksum、展開物と現行ソースの照合に成功。

製品コードは変更していない。MT-069の修正・回帰、MT-006の方針確定、MT-070の実機確認が必要なため、Phase 7は`In progress`、Phase 8は`Blocked`、判定は **No-Go** を維持する。詳細は`docs/MANUAL_TEST_HIGH_COMPLETION_REPORT.md`を参照。

以下は今回のHigh完了試験より前の記録として保持する。

## 2026-09-26 CC-001／CC-002 修正・回帰追補

CC-001とCC-002を限定修正し、`make check`、新RC ZIPの完全性確認、ソース非マウント環境でのChromium実ブラウザ回帰を実施した。MT-036、052は成功、限定回帰のMT-034、045、050も成功した。Criticalは成功44・失敗0・保留0、Highは成功18・失敗0・保留10、全72件は成功62・失敗0・保留10。Phase 7は`In progress`、Phase 8は`Blocked`、判定は **No-Go** を維持する。

- MT-036: 実行中と終端直後に全体・完了・未処理・失敗・スキップ・現在の状態を日本語表示。`aria-live`、自動batch、閉鎖・二重実行拒否も成功。
- MT-052: 利用者が実行を受諾後、delete対象が全て開始前に処理不能となる場合だけを`failed`とし、履歴・理由・日本語案内を保存。Item／Journal 0、データ不変。一般のstaleやnonce／権限／ロック拒否は`previewed`を維持。
- 自動検証: PHPCS 62/62、JavaScript lint、PHPUnit 119 tests / 1,466 assertions、Ajax PHPUnit 1 test / 6 assertionsを含む`make check`が成功。
- RC: `dist/taxonomy-tidy-0.1.0.zip`、SHA-256 `528362390fa5183d53fd6e938786351ecc4ce5f71f51a10e637c89bbbacd1610`。`unzip -t`／checksum照合成功。
- 証跡: `build/manual-test/critical-fix-retest/evidence/group-b/`、`group-c/`。両環境の`debug.log` 0 byte、製品起因PHPエラー、未処理JavaScriptエラー、予期しない4xx／5xx、重複処理は0件。

以下は修正前またはそれ以前の記録として保持する。

## 2026-09-26 残存Critical 11件 完了追補

現行RC ZIPをソース非マウントの4つの独立Docker環境へ管理画面から導入し、保留だったCritical 11件をChromium実ブラウザで実施した。結果は成功9件、失敗2件、保留0件。Critical全体は成功42・失敗2・保留0、Highは成功18・失敗0・保留10、全72件は成功60・失敗2・保留10となった。

- MT-036: 実行中モーダルのロック、自動batch、aria-liveは機能するが、必須進捗項目の「未処理」「スキップ」が表示されない。
- MT-052: 全Item開始不能を無変更で安全拒否するが、Operationが期待する`failed`ではなく`previewed`に残る。
- MT-027、034、038、039、044、049、050、051、068は期待結果まで成功した。
- 4環境の`debug.log`は0 byte。未処理JavaScriptエラー、予期しない4xx/5xx、対象外変更、重複Item/Journalは0件だった。
- 対象RC: `dist/taxonomy-tidy-0.1.0.zip`、SHA-256 `48025c0f330f5ed829e103448c92a5e25aea9cad08d72f40f030174887d798e2`。

Critical失敗2件を公開阻害とし、Phase 7は`In progress`、Phase 8は`Blocked`、判定は **No-Go** を維持する。詳細と証跡は`docs/MANUAL_TEST_CRITICAL_COMPLETION_REPORT.md`を参照。

## 2026-09-26 MT-061修正追補

判定は引き続き **No-Go**。ただしMT-061の製品失敗は解消し、Criticalは成功33、失敗0、保留11、Highは成功18、失敗0、保留10となった。Phase 7は`In progress`、Phase 8は`Blocked`を維持する。

- 不正nonceを明示JSON 403、非再試行、再読み込み／再ログイン案内へ修正した。
- stale previewをAjax／通常POSTとも409へ統一し、開始前競合は子Item／Journalを作らず管理者変更を保持する。
- 旧MT-061C-4は開始後・バッチ間のC-4bだったため、200で安全なItemを継続し競合Itemだけ失敗させる動作が仕様どおりと確定した。開始前C-4aは別ケースで409停止を確認した。
- `make check`は通常PHPUnit 114 tests / 1,421 assertionsとAjax PHPUnit 1 test / 6 assertionsを含め成功した。
- MT-061A/B/C全条件、MT-058～060、MT-062A/B、MT-063 ChromiumのRCブラウザ回帰が成功した。Firefox／WebKitのMT-063は変更影響外のため2026-09-25の成功証跡を再利用した。
- ソース未マウントの新規WordPress環境で、名称変更、2-source統合、15タグ削除、12カテゴリー削除、30件超の複数Item Undoを確認した。予期しないConsole／Network／PHPエラーは0件、`debug.log`は0行だった。
- RC: `dist/taxonomy-tidy-0.1.0.zip`、SHA-256: `48025c0f330f5ed829e103448c92a5e25aea9cad08d72f40f030174887d798e2`。`unzip -t`とchecksum照合に成功した。

以下は初回Phase 8検証時点の記録として保持する。

## 結論

判定は **No-Go**。統合先は、操作性を優先して入力なしで同一taxonomy全体の候補を確認できる選択欄とした。1,000タグ時はHTMLが増加する既知の制限を許容しつつ、一覧テーブルはサーバー側ページネーションを維持する。一方、現行UIを含む実ブラウザでの主要受け入れ試験は未完了である。

## 実行情報

- 実行日: 2026-09-19（Asia/Tokyo）
- 開始時Git HEAD: `65828f028fc16483128180f4325dc308cebbdbb1`
- 開始時作業ツリー: clean
- 終了時作業ツリー: Phase 8の文書、配布・検証手順、README、CHANGELOG、LICENSEに未コミット変更あり
- Docker: 29.7.2
- Docker Compose: 5.5.1
- Composer: 2.8.12
- Node.js: 22.23.2
- ブラウザ: 利用可能な実行環境なし（未検証）

## 対応環境の結果

| WordPress | PHP | DB | 結果 |
|---|---:|---|---|
| 6.6.2 | 8.2 | MySQL 8.0.46 | ZIP導入、有効化、管理画面サーバー描画、debug.log成功 |
| 6.6.2 | 8.3 | MySQL 8.0 | 統合テスト99件・1,313 assertions成功 |
| 6.6.2 | 8.4 | MySQL 8.0 | 統合テスト99件・1,313 assertions成功。Coreテスト導入コードの非推奨警告1件 |
| 7.1.1 | 8.4.25 | MySQL 8.0 | ZIP導入、有効化、管理画面サーバー描画、debug.log成功 |
| 6.6.2 | 8.2 | MariaDB 10.11.19 | ZIP導入、有効化、3テーブル作成、再導入成功 |

WordPress 7.1.1は実行日のWordPress.org現行安定版として確認した。

## バージョン整合性

- プラグインヘッダー: `0.1.0`
- `TAXONOMY_TIDY_VERSION`: `0.1.0`
- Composer package: `taxonomy-tidy/taxonomy-tidy`（root packageの固定version指定なし）
- README / CHANGELOG / ZIP名: `0.1.0`
- DBスキーマ: `1`（プラグイン版とは独立した内部スキーマ番号）
- 最低対応: WordPress 6.6、PHP 8.2、MySQL 8.0またはMariaDB 10.11
- `readme.txt`: 存在しない。配布ZIPには利用者向け`README.md`を含める。

## 自動・静的検証

| コマンド | 結果 |
|---|---|
| `make check` | 成功。PHPCS 60/60、JavaScript lint、104 tests / 1,343 assertions |
| PHP 8.3 `docker compose ... test` | 成功。99 tests / 1,313 assertions |
| PHP 8.4 `docker compose ... test` | 成功。99 tests / 1,313 assertions |
| `composer validate --strict --no-check-publish` | 成功 |
| `composer audit --locked` | 既知の脆弱性なし |
| `composer licenses` | 本番依存なし。dev依存はMIT、BSD-3-Clause、LGPL-3.0-or-later |
| PHP `-l` | 本体、テスト、ツールの全PHPで成功 |
| JSON parse | `package.json`、`package-lock.json`成功 |
| `xmllint --noout` | PHPCS、PHPUnit設定成功 |
| `sh -n bin/*.sh` | 成功 |
| `docker compose config --quiet` | 開発用・スモーク用とも成功 |
| `git diff --check` | 成功 |

YAMLはComposeの設定解決で検証した。PHP 8.4でWordPress 6.6.2のテストライブラリを導入すると、Core側`install.php`から`E_STRICT`非推奨警告が1件出る。WordPress 7.1.1 / PHP 8.4.25の通常実行ではwarning、notice、deprecatedを記録しなかった。

## MVP要件との照合

Phase 1〜6の骨格、永続化、正確な一覧、計画・プレビュー、バッチ実行、履歴・Undoはコードと104件の統合テストで確認した。Phase 7の4タブ、アコーディオン、ページネーション、モーダル、ARIA、日本語カタログ、入力不要の統合先選択も実装・自動テストが存在する。ただしPhase 7文書のとおり実ブラウザ確認が未完了で、状態は`In progress`のままである。

確認できた主な自動シナリオ:

- 公開済み標準投稿数と全relationship数の分離
- 名前・slug検索、並べ替え、未使用絞り込み、数値ページネーション
- 名称だけ／slugだけの変更、slug競合拒否
- 複数source統合、既存destination、無関係assignment保持、対象外オブジェクト保持
- 複数削除、使用中term・デフォルトカテゴリー拒否、削除直前再確認
- 計画ハッシュ、状態フィンガープリント、古いプレビュー拒否
- 有界バッチ、中断・再開、冪等性、ロック、部分失敗
- owner、capability、nonce、taxonomy許可リスト
- 履歴、Undo、後からの管理者変更と競合する場合の非上書き

## クリーンインストールとスキーマ

`taxonomy-tidy-phase8`という別Composeプロジェクトと専用ボリュームを作り、ソースをマウントせず、`dist/taxonomy-tidy-0.1.0.zip`だけをインストールした。

- WordPress起動: 成功
- ZIPインストール・有効化: 成功
- `vendor/autoload.php`: 存在
- operations / operation_items / changes: 作成
- schema option: `1`
- 管理メニュー: 登録
- 管理者アクセス: 許可
- 一部権限だけの利用者: 拒否
- CSS / JavaScript: HTTP 200
- 無効化・再有効化: 成功
- `debug.log`: 空

Operation、Operation Item、Change Journalを専用環境へ作成後、`Schema::install()`を2回実行し、3レコードが保持されることを確認した。再有効化後もOperationを保持した。過去の正式リリースはないため、旧配布版からの移行試験は存在しない。

無効化後のアンインストールとZIP再インストールも成功した。アンインストールでは復旧・監査データを削除せず3テーブルを保持する。

## 手動・スモーク確認

- 未認証の管理画面URL: ログイン画面へHTTP 302
- 管理者コンテキスト: カテゴリー、タグ、操作計画、履歴の4画面をサーバー描画
- タグ初期一覧: 20行以内
- アコーディオン: 初期`aria-expanded="false"`
- WordPress 7.1.1 / PHP 8.4: ZIPから有効化し、管理画面描画後もdebug logは空

実ブラウザがなかったため、視覚、狭幅、キーボード、フォーカストラップ・復帰、Esc、背景クリック、スクリーンリーダー、およびUIからの名称変更・統合・複数削除・複数計画・中断再開・履歴・Undoは今回未実施。`docs/EXECUTION.md`と`docs/UNDO.md`に以前の実ブラウザスモーク記録はあるが、今回のリリース候補ZIPの代替とは扱っていない。

## 性能確認

専用WordPress 6.6.2 / PHP 8.2 / MySQL 8.0環境へ再現可能なlargeシードを37秒で作成した。WordPress初期データを含む実数は101カテゴリー、1,000タグ、321公開投稿で、シード定義自体は100カテゴリー、1,000タグ、320公開投稿、50下書き、25非公開、25予約投稿である。

| ケース | 所要時間 | SQL数 | 結果 |
|---|---:|---:|---|
| カテゴリー初期20件 | 8.84ms | 2 | 101件、6ページ |
| カテゴリー完全未使用 | 5.22ms | 2 | 10件 |
| タグ初期20件 | 21.01ms | 2 | 1,000件、50ページ |
| タグ100件・10ページ目 | 18.09ms | 2 | 100件描画、全10ページ |
| タグ名前検索 | 6.29ms | 3 | 10件 |
| タグslug検索 | 2.55ms | 2 | 10件 |
| タグ完全未使用 | 16.52ms | 2 | 100件 |

一覧SQLはN+1にならず、表示件数上限も守られた。統合先選択の方式別比較は次のとおり。

| 項目 | 旧二重描画 | 非同期検索版 | 現行選択欄 |
|---|---:|---:|---:|
| タグ管理画面HTML | 約328KB | 44,430 bytes（約43.4KiB） | 132,661 bytes（約129.6KiB） |
| 初期HTMLの統合先候補 | 1,000件を2組 | 0件 | 1,000件を1組 |
| 一覧テーブル行 | 20件 | 20件 | 20件 |
| 入力なしの候補確認 | 可 | 不可 | 可 |
| 候補の表示文字列 | ID・名前・slugを含む | 名前・slug | 名前のみ |

使用データはシード定義100カテゴリー（WordPress既定分を含む実数101）、1,000タグ、320公開投稿、50下書き、25非公開、25予約投稿である。現行実装では一覧条件と独立した`get_terms()`で同一taxonomy全体を取得し、候補を1組だけ描画する。自動テストで、入力不要、現在ページ外・検索条件外の候補、category/post_tagの分離、複数統合元のID除外、名前だけの表示、安定IDの内部保持、HTML escapeを確認した。JavaScriptは統合元の変更時にoptionを安定IDで再評価し、選択済みdestinationがsourceになった場合に解除する。非同期検索エンドポイントと専用コードは他用途がないため削除した。

## セキュリティ確認

- 管理画面とPOSTの共有capability検証をコードとテストで確認
- POST nonce、Operation owner、状態、taxonomy、term ID / term taxonomy IDをテストで確認
- 計画ハッシュ、状態フィンガープリント、古いプレビュー、ロック所有者・期限をテストで確認
- SQLはInfrastructure層にあり、動的値をprepared queryへ渡す構成を確認
- 出力escapeと日本語カタログの統合テスト成功
- ZIP内に`.env`、Git、IDE設定、テスト、Docker、開発依存がないことを確認
- 認証情報、Cookie、nonce値、個人情報を本レポートへ記録していない

実ブラウザを使うXSS、CSRF、フォーカス、DOM重複IDの動的検証は未実施。自動テストでは不正nonce、他所有者、taxonomy跨ぎ、競合、XSSとなる表示値のescapeを確認している。

## 配布物

- ZIP: `dist/taxonomy-tidy-0.1.0.zip`
- SHA-256: `50da61d6009aae7d2ed9e67be7cbfdefcf78eb65148354e4288962f9943418ae`
- チェックサムファイル: `dist/taxonomy-tidy-0.1.0.zip.sha256`
- 生成: `make dist`
- 構造: 直下に`taxonomy-tidy/`が1つ
- 再現性: 連続2回の生成でSHA-256一致

現行の統合先選択UIを含むZIPを同一ツリーから2回生成し、SHA-256の一致、`unzip -t`、内容確認に成功した。クリーンインストールと実ブラウザ確認は現行ZIPでは再実施していない。

## 失敗・未検証・既知の制限

### リリース阻害

1. **P1 — リリース候補ZIPの主要UI手動試験が未完了**
   - 実ブラウザ環境がなく、名称変更、統合、複数削除、複数Operation、中断・再開、履歴、Undo、アクセシビリティをRC ZIPから確認できていない。
   - 推奨: ブラウザを用意し、`docs/RELEASE_CHECKLIST.md`の未完了項目を実施する。

### 既知の制限

- 統合先の選択性を優先し、統合先候補は対象taxonomyから全件表示する。1,000件規模では管理画面HTMLが増加するが、一覧テーブル自体はサーバー側ページネーションを維持する。初期転送量の削減は将来の改善候補とする。
- categoryとpost_tagだけを対象とする。
- relationship変更は公開済み標準投稿だけを対象とする。
- Redo、CSV、定期実行、カスタムtaxonomy・post type、マルチサイト一括処理は対象外。
- Undoは後続変更や競合を上書きせず、完全に戻せない場合がある。
- アンインストールで操作履歴テーブルを自動削除しない。
- 正式な旧配布版がないため、旧版からのアップグレードは未検証。

## Go / No-Go

**No-Go**。全件候補表示は操作性を優先した既知の制限として許容するが、現行選択UIを含む主要UIの実ブラウザ試験が未完了である。Phase 7は`In progress`、Phase 8は`Blocked`のままとし、外部公開も行っていない。

## 2026-09-19 Undo自動継続・履歴ログ改善の追補

Undoの利用者操作を1回にしながら、`UndoWorkflow::BATCH_SIZE = 10`、pending Item、60秒のtaxonomyロック、項目単位の状態、サーバー進捗、Change Journalの一意キーを維持した。各Ajax応答は処理済み、成功、失敗、残り、全体、状態、次バッチ要否を返し、ブラウザは`undoing`かつ残件ありの場合だけ次を要求する。通信層の失敗は最大3回、権限・nonce・競合・ロック・継続不能応答は再試行せず、進捗が前進しない応答にも停止ガードを設けた。中断状態は操作履歴の`取り消しを再開`から1回で残りを自動継続する。

操作詳細はJournal全件を初期HTMLへ出さず、件数要約と、エラー、警告、新しい結果の優先順による最大5件だけを描画する。6件以上の`詳しく見る`は、権限、nonce、Operation所有者、開始済み状態、Undo親子関係を検証し、最大100件ずつ取得して全件を表示する。表示ラベルは内部ID、JSON、例外、クラス名を含めず、CSSで長い名前・URL相当の連続文字列を親幅内へ折り返す。

追加・更新した自動検証は、30件を10件ずつ3リクエストで完了、初期ログ5件上限、エラー・警告優先、ログ件数集計とページング、自動継続条件、終端停止、進捗停滞ガード、最大3回再試行、遅延展開のARIA状態である。実ブラウザによる30件Undo、通信断、再開、ログ開閉、狭幅、キーボード、支援技術の確認は未実施である。このためPhase 7は`In progress`、Phase 8は`Blocked`、判定は`No-Go`のままとする。
