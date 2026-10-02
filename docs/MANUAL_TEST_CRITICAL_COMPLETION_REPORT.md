# 残存Critical 11件 実ブラウザ試験レポート

> 旧開発名称：Taxonomy Tidy／現製品名称：Term Steward。以下はリブランド前の試験証跡です。

## 2026-09-27 最終判定時の確認

Critical全44件の最新結果と証跡を`docs/MANUAL_TEST_PLAN.md`から再確認し、成功44・失敗0・保留0と再集計した。修正前のMT-036／052失敗は履歴であり、CC-001／CC-002修正後の成功結果を最新として採用する。MT-061CもC-1、C-2、C-3、C-4a、C-4b、C-5の全条件が成功済みである。

最終作業ツリーの製品差分は操作結果通知のclassと表示CSSだけで、Criticalのデータ変更経路には変更がない。現行RC ZIPで影響する名称変更、結果通知、操作履歴、Undo、再読み込み時のモーダル非再表示をChromiumで限定回帰し成功した。slugと無関係assignmentを保持し、重複Item／Journal 0、製品起因PHPエラー、未処理JavaScriptエラー、予期しないNetworkエラー0を確認した。複数統合、複数削除、対象外データ保持、二重処理防止は本書および既存の回復・Undo証跡を再利用した。

最終RCは`dist/taxonomy-tidy-0.1.0.zip`、SHA-256は`f79e2f7d300e8e528551c2e9b62baecf2ef4b4f6858d1f250aba93558884793f`。今回の判定基準ではCriticalに影響しないHighの残存事項を公開阻害としないため、最終判定は`Go`、Phase 7／8は`Completed`とする。以下の2026-09-26時点のNo-Goと修正前失敗は履歴として保持する。

## 2026-09-26 CC-001／CC-002 修正・回帰追補

CC-001とCC-002の原因を限定修正し、新RC ZIPをソース非マウントの独立Docker環境へ管理画面から導入してChromiumで再試験した。MT-036とMT-052は成功し、限定回帰のMT-034、045、050も成功した。Criticalは成功44・失敗0・保留0、Highは成功18・失敗0・保留10、全72件は成功62・失敗0・保留10となった。High保留10件が残るため、Phase 7は`In progress`、Phase 8は`Blocked`、判定は`No-Go`を維持する。

- CC-001: 保存済みItem状態の集計は存在したが、モーダルが`completed`、`total`、`failed`しか描画していなかった。実行中と終端直後に「全体、完了、未処理、失敗、スキップ、現在の状態」を常に日本語表示し、実行中の`aria-live`を維持した。
- 進捗集計: `total`は永続化された全Item数、`pending`は未処理、`completed`は成功、`failed`は失敗、`skipped`は安全上変更しなかったItemとする。各Itemは相互排他の1状態だけを持ち、`total = pending + completed + failed + skipped`とする。
- CC-002: 通常のstale previewと、利用者が実行を受諾した後にdelete計画の全対象が開始前安全検証で処理不能となる終端失敗を区別した。後者だけを既存状態グラフの`previewed -> running -> failed`で保存し、開始・完了日時、理由、履歴、日本語の再計画案内を残す。Item／Journalとterm／relationshipは作成・変更しない。一般のstale、nonce、権限、ロック拒否は`previewed`のままとした。
- 変更した製品ファイル: `src/Admin/ErrorMessages.php`、`src/Admin/HistoryPage.php`、`src/Admin/PlanBoard.php`、`src/Application/Execution/ExecutionErrorCode.php`、`src/Application/Execution/ExecutionWorkflow.php`、`languages/taxonomy-tidy.pot`、`languages/taxonomy-tidy-ja.po`、`languages/taxonomy-tidy-ja.mo`。`HistoryPage.php`と翻訳カタログにあった開始時の既存変更は保持した。
- 変更したテストファイル: `tests/Integration/ExecutionWorkflowTest.php`、`tests/Integration/PersistenceTest.php`、`tests/Integration/PlanBoardTest.php`。
- 変更した文書: `docs/MANUAL_TEST_PLAN.md`、`docs/MANUAL_TEST_CRITICAL_COMPLETION_REPORT.md`、`docs/TEST_REPORT.md`、`docs/RELEASE_CHECKLIST.md`、`docs/IMPLEMENTATION_PLAN.md`。
- 自動検証: `make check`成功（PHPCS 62/62、JavaScript lint、PHPUnit 119 tests / 1,466 assertions、Ajax PHPUnit 1 test / 6 assertions）。
- RC: `dist/taxonomy-tidy-0.1.0.zip`、SHA-256 `528362390fa5183d53fd6e938786351ecc4ce5f71f51a10e637c89bbbacd1610`。`unzip -t`とchecksum照合に成功。
- MT-036／045: `build/manual-test/critical-fix-retest/evidence/group-c/`。MT-036は15 Itemの自動batch中に6項目、`aria-live`、閉鎖・二重実行拒否を確認。MT-045はcompleted終端の6項目と古いモーダルの非再表示を限定回帰したが、同ケースのpartial_failed表示未確認のためHigh保留は維持する。
- MT-050／052: `build/manual-test/critical-fix-retest/evidence/group-b/`。MT-052は部分競合=`partial_failed`、全対象処理不能=`failed`、ロック後の正常系=`completed`。全体失敗はItem／Journal 0、term／relationship不変、成功表示なし、自動再実行0。
- ログ: 両環境の`debug.log`は0 byte。製品起因PHP Warning／Notice／Deprecated／Fatal、未処理JavaScriptエラー、予期しない4xx／5xx、対象外変更、重複Item／Journalは0件。
- Git HEADは`b8de258b64d46f6a5990cc8f0933292e44c817a0`。開始時からdirtyで、既存の未コミット変更を保持した。コミット、push、GitHub Release、WordPress.org公開は行っていない。

以下は修正前の実ブラウザ試験記録として保持する。

## 1. 結論

2026-09-26、現行RC ZIPをソース非マウントの独立Docker環境へ管理画面から導入し、残存Critical 11件をChromium実ブラウザで実施した。結果は成功9件、失敗2件、保留0件である。MT-036は実行中進捗に「未処理」「スキップ」が表示されない。MT-052は全Itemが開始不能な場合にOperationが`failed`へ遷移せず`previewed`のまま残る。この2件を公開阻害事項とする。

Critical全体は成功42件、失敗2件、保留0件となった。Highは成功18件、失敗0件、保留10件、全72件は成功60件、失敗2件、保留10件である。Phase 7は`In progress`、Phase 8は`Blocked`、最終判定は`No-Go`を維持する。製品コード、外部公開、WordPress.org申請、SVN登録は変更・実施していない。

## 2. 試験対象の固定

- Git HEAD: `b8de258b64d46f6a5990cc8f0933292e44c817a0`
- 作業ツリー: 開始時dirty。ユーザーの既存変更を保持した。
- RC ZIP: `dist/taxonomy-tidy-0.1.0.zip`
- SHA-256: `48025c0f330f5ed829e103448c92a5e25aea9cad08d72f40f030174887d798e2`
- RC整合性: ZIP内の製品ファイルは開始時の作業ツリーと一致。HEAD単体とは一致せず、未コミットの`src/Admin/Page.php`、`src/Admin/HistoryPage.php`、翻訳カタログ変更を含む。ZIP生成後の製品コード変更は検出しなかった。
- WordPress: 6.6.2、日本語、Asia/Tokyo
- PHP: 8.2.25
- DB: MySQL 8.0.46
- ブラウザ: Chrome for Testing / Chromium 153.0.8010.12
- Docker: 29.7.2、Docker Compose v5.5.1
- Compose project: `taxonomy-tidy-critical-a-20260926`、`-b-`、`-c-`、`-d-`
- `WP_DEBUG=true`、`WP_DEBUG_LOG=true`、`WP_DEBUG_DISPLAY=false`
- 開始日時: 2026-09-26 10:56 JST
- Safari実機: 未確認。MT-063 Firefox／WebKitは2026-09-25の既存成功証跡を再利用した。

各projectは専用DB volume、WordPress volume、ポート、管理者、ブラウザcontext、fixture、ログ、証跡先を使用した。同時実行は最大2系統とした。

## 3. ケース別結果

### 操作手順と期待結果

- MT-027: 既定カテゴリー、公開・下書き・非公開・固定ページで使用中のterm、未使用termとの混合を削除計画へ追加し、全拒否・日本語理由・全データ不変を期待した。
- MT-034: 15 Itemの計画を低速化し、連続Enter、ダブルクリック、別タブ同時開始を行い、1 Operationだけの開始、重複Item/Journalなし、主要footer操作2つを期待した。
- MT-036: 15 Itemの自動batch中にキャンセル、閉じる、Esc、背景、再実行、Tabを試し、閉鎖・二重実行拒否と「全体、完了、未処理、失敗、スキップ、状態」の日本語進捗を期待した。
- MT-038／039: 複数sourceを1 destinationへ1回の実行操作で統合し、自動batch、厳密なassignment、既存destination、無関係term、別taxonomy、安全なsource削除、Item/Journal整合を期待した。
- MT-044: 子categoryを持つsourceを統合し、公開投稿だけの移動、sourceと親子構造の保持、プレビュー・結果・履歴の保持理由を期待した。
- MT-049: preview後に別管理者contextでsource／destinationのname・slugを変更し、開始前拒否、後続batch 0、予定変更0、管理者変更保持、日本語再確認案内を期待した。
- MT-050: relationshipを開始前と1 batch後に別々に変更し、前者の全拒否、後者の安全Item継続と`partial_failed`、固定対象外の新規投稿除外、管理者変更保持を期待した。
- MT-051: preview後にsource／destinationを別試行で削除し、開始前拒否、別termへの誤適用なし、成功・内部例外非表示を期待した。
- MT-052: 部分競合、全Item開始不能、同taxonomy同時開始を分離し、それぞれ`partial_failed`、`failed`、安全なロック拒否と重複なしを期待した。
- MT-068: 指定されたキーだけで4タブからUndo完了まで操作し、論理的で可視なfocus、trap、起点復帰、全操作到達、データ復元を期待した。

すべて`docs/MANUAL_TEST_PLAN.md`の正本手順を使用した。HTTP、request、自動再送、Operation/Item/Journal、term、relationship、対象外データ、Console、Network、debug/PHPログをケースごとに採取・比較した。

### 実際の結果

| ケース | 結果 | HTTP／request | Operation終端 | 実際の結果・データ確認 | 証跡 |
|---|---|---|---|---|---|
| MT-027 | 成功 | HTTP 200。拒否ごとに1 POST、自動再送0 | 空の`draft`のみ、Item/Journal 0 | 既定、公開、下書き、非公開、固定ページ使用中のcategory/tagと未使用termとの混合を日本語理由で全拒否。term・relationship不変 | `build/manual-test/critical-completion/evidence/group-a/` |
| MT-034 | 成功 | `run_all` 2要求（別タブ競合を含む）、自動再送0 | `completed` | 連続Enter、ダブルクリック、別タブ同時操作でもOperation 1件、Item 15件は各attempt 1、Journal 15件で一意。主要footer操作はキャンセルと実行だけ | `group-c/MT-034-*` |
| MT-036 | **失敗** | `run_all` 1要求、`continue_all`自動継続 | 試験操作は`completed` | 実行中のEsc・閉じる・背景・再実行は拒否し、aria-liveと自動batchは機能。ただし表示は「完了 10 / 全体 15、失敗 0」で、「未処理」「スキップ」を表示しない | `group-c/MT-036-failure.png`、`network.json` |
| MT-038 | 成功 | 実行1回、HTTP 200 | `completed`（Item 6、Journal 10） | A/Bを全対象公開投稿から外しTARGETを重複なく付与。既存TARGET、UNRELATED、post_tag不変。source削除、Item/Journal一意 | `group-a/MT-038-*` |
| MT-039 | 成功 | 実行1回、HTTP 200 | `completed`（Item 6、Journal 10） | source共有投稿と既存TARGETを含め厳密比較。UNRELATED、category不変。source削除、Item/Journal一意 | `group-a/MT-039-*` |
| MT-044 | 成功 | 実行1回、HTTP 200 | `completed`（completed 1、skipped 1、Journal 3） | 公開投稿だけTARGETへ移動。sourceと子categoryのparentを保持。プレビュー、result_data/warnings、履歴詳細で保持理由を確認 | `group-a/MT-044-*`、`MT-044-history.png` |
| MT-049 | 成功 | source／destination各1要求、HTTP 200内で開始拒否、自動再送0 | 各`previewed`、Item/Journal 0 | sourceのname/slug、destinationのname/slugを別管理者contextで変更。予定変更0、管理者変更保持、日本語再確認案内 | `group-b/MT-049A-*`、`MT-049B-*` |
| MT-050 開始前 | 成功 | 1要求、HTTP 200内で開始拒否、自動再送0 | `previewed`、Item/Journal 0 | relationship変更を検出して全開始拒否。管理者変更を保持 | `group-b/MT-050A-*` |
| MT-050 開始後 | 成功 | 開始1回、後続batch自動継続 | `partial_failed` | 競合Itemを上書きせず安全Itemのみ継続。プレビュー後にsourceを付けた新規投稿は固定対象へ含めず、Item/Journal重複なし | `group-b/MT-050B-*` |
| MT-051 source削除 | 成功 | 1要求、開始前拒否、自動再送0 | `previewed`、Item/Journal 0 | source消失を検出し、別term・relationshipへ誤適用せず、成功表示・内部例外表示なし | `group-b/MT-051A-*` |
| MT-051 destination削除 | 成功 | 1要求、開始前拒否、自動再送0 | `previewed`、Item/Journal 0 | destination消失を検出し、無関係データ不変 | `group-b/MT-051B-*` |
| MT-052 部分競合 | 成功 | 開始1回、後続batch自動継続 | `partial_failed` | 成功・失敗Item数が実数と一致し、競合変更を保持。重複Item/Journalなし | `group-b/MT-052A-*` |
| MT-052 全体開始不能 | **失敗** | 1要求、開始前拒否、自動再送0 | **`previewed`のまま（期待`failed`）** | データ変更、Item、Journalは0で安全。ただし成功Item 0の終端失敗を`failed`として記録・表示する期待結果を満たさない | `group-b/MT-052B-*` |
| MT-052 ロック競合 | 成功 | 2タブ同時要求、競合側安全拒否 | 1 Operationだけ開始・終端 | 同一taxonomyの重複処理、同一Item再処理、重複Journalなし | `group-b/MT-052C-*` |
| MT-068 | 成功 | HTTP 200、自動batch | 元操作`completed`、子Undo`undone` | ポインティングデバイスなしで4タブ、検索・処理accordion、検索、選択、radio矢印操作、計画、preview cancel/reopen/execute、履歴詳細、詳しく見る、Undo preview cancel/reopen/executeを完了。trap・復帰・可視focus・assignment復元を確認 | `group-d/MT-068-*` |

## 4. 公開阻害事項

### CC-001: MT-036 実行中進捗の必須項目不足

- 期待: 全体、完了、未処理、失敗、スキップ、状態を日本語表示する。
- 実際: taxonomyと状態、「完了／全体／失敗」だけを表示し、未処理とスキップを表示しない。
- 原因候補: `src/Admin/PlanBoard.php`の実行モーダル進捗描画が`completed`、`total`、`failed`だけを出力している。
- データ影響: なし。ロック、自動継続、二重実行防止、aria-live、終端状態は正常。
- 回避策: 操作履歴または終端後の保存結果で件数を確認できるが、実行中の必須情報不足は回避できない。
- 状態保全: `taxonomy-tidy-critical-c-20260926`のDB／volumeと証跡を停止・削除せず保持した。

### CC-002: MT-052 全Item開始不能時に`failed`へ遷移しない

- 期待: 成功Itemがない開始不能は`failed`とし、completedと区別する。
- 実際: stale previewとして変更前に安全拒否するが、Operationは`previewed`のまま、Item/Journal 0件で残る。
- 原因候補: `src/Admin/PlanBoard.php::run_all()`のpreflight例外経路と`src/Application/Execution/ExecutionWorkflow::validate_start()`が、開始前拒否をOperationの`failed`終端へ保存しない。
- データ影響: なし。term、relationship、Item、Journalは不変。
- 回避策: 計画を破棄して再確認できるが、履歴上の終端失敗記録と再実行可否表示が要件を満たさない。
- 状態保全: `taxonomy-tidy-critical-b-20260926`のDB／volumeと証跡を停止・削除せず保持した。

## 5. ログと安全性

- 製品起因のPHP Warning／Notice／Deprecated／Fatal: 0件
- 未処理JavaScriptエラー: 0件
- 予期しない4xx／5xx: 0件
- データ破壊・対象外変更: 0件
- 二重処理・重複Journal: 0件
- ハーネス開発中の列名・待機条件・DB復元不備はクリーン再試験で除外し、正式結果へ数えていない。
- Group Aの生`results.json`に残るMT-027／044のハーネス誤判定は、DB snapshotと追加履歴詳細確認に基づき`adjudicated-results.json`で訂正した。生証跡自体は改変していない。

## 6. 判定

- Critical: 44件中、成功42、失敗2、保留0
- High: 28件中、成功18、失敗0、保留10
- 全72件: 成功60、失敗2、保留10
- Phase 7: `In progress`
- Phase 8: `Blocked`
- 最終判定: **No-Go**

Critical保留は解消したがCritical失敗2件とHigh保留10件、Safari実機未確認が残るため、Critical完了・Phase完了・公開可能とは扱わない。
