# Taxonomy Tidy 0.1.0 回復・データ境界実ブラウザ試験報告

## 1. 結論

指定された7件を、現在のソースから生成したRC ZIPと、ソースをマウントしない破棄可能なローカルDocker環境で実施した。MT-043、MT-046、MT-047、MT-048、MT-061、MT-062は成功し、MT-063は失敗した。

MT-063では同時Undoの一方をHTTP 409で安全に拒否し、開始済みUndo、Item、Journal、term、assignmentの重複やデータ不整合はなかった。しかし、2つのUndoプレビューが同じ元Operation配下へ2つの子Operationを作成し、勝者の`undone`に加えて拒否側の未開始`undo_previewed`が残った。「子Undo Operationを重複作成しない」という成功条件を満たさないため失敗とした。

今回の更新後の全体集計は成功47件、失敗1件、保留24件である。Critical失敗と他のCritical／High保留が残るため、判定は**No-Go**、Phase 7は`In progress`、Phase 8は`Blocked`のままとする。製品コードは変更していない。

## 2. 対象ソース、作業ツリー、RC ZIP

| 項目 | 値 |
|---|---|
| Git commit | `0f8549adbc48fb4882d6f57af9023f7edc0f4d16` |
| RC ZIP | `dist/taxonomy-tidy-0.1.0.zip` |
| SHA-256 | `1bb18c904bd05993e865c10acc3b2347087e6e4361bc81070f92843d0dc5b0be` |
| ZIP検査 | `unzip -t`成功。必要な本番ファイルを含み、`tests/`、`tools/`、`docs/`、Docker、Node、`.git`、`.env`を含まない |
| 作業ツリー | MT-033／064修正とテスト、`docs/MANUAL_TEST_PLAN.md`が変更済み。`docs/MANUAL_TEST_REPORT.md`と`docs/MANUAL_TEST_RETEST_REPORT.md`は未追跡。既存変更を保持 |
| 今回の製品コード変更 | なし |

RC ZIPは`make check`が成功した同じ作業ツリーから`make dist`で生成した。旧`MANUAL_TEST_REPORT.md`と`MANUAL_TEST_RETEST_REPORT.md`は変更していない。

## 3. 事前ゲート

- `make check`: 成功。
  - PHPCS: 61 / 61 files
  - JavaScript lint: 成功
  - PHPUnit / WordPress統合テスト: 108 tests、1,380 assertions
- `make dist`: 成功。
- SHA-256取得: 成功。
- `unzip -t`: 成功。
- 配布内容／開発物・秘密情報除外: 成功。
- RC ZIPの管理画面アップロード・有効化: 全ケース環境で成功。
- `WP_DEBUG=true`、`WP_DEBUG_LOG=true`、`WP_DEBUG_DISPLAY=false`: 全環境で設定。
- Playwrightからの日本語WordPress管理画面操作: 成功。
- WP-CLIによる正規化JSON取得: 成功。
- 各ケース開始直前に、databaseコンテナ内`/tmp/pre-case.sql`へ復元用DB dumpを作成し、非空を確認した。dumpは証跡へコピーせず、環境破棄時に削除した。

## 4. 実施環境と分離

| 項目 | 値 |
|---|---|
| WordPress | 6.6.2 |
| PHP | 8.2.28 |
| DB | MySQL 8.0.46 |
| ブラウザ | Chromium 153.0.8010.12（Playwright 1.63.0） |
| 言語／タイムゾーン | 日本語／Asia/Tokyo |
| 実施日時 | 2026-09-20 21:15～21:37 JST |
| インストール | 新規WordPressへ管理画面からRC ZIPをアップロードして有効化 |

ケースごとにCompose project、WordPressコンテナ、DBコンテナ、両volume、公開ポート、テスト用データ接頭辞、証跡ディレクトリを分離し、順番に実行した。ソースディレクトリはWordPressへマウントしていない。MT-061とMT-062はパターンA／Bを別環境に分離した。

## 5. ケース別結果

| ケース | 結果 | 主な確認結果 |
|---|---|---|
| MT-043 | 成功 | categoryとpost_tagを同じ1回の実行から処理。公開済み標準投稿だけをdestinationへ変更し、下書き、非公開、予約、承認待ち、ゴミ箱、固定ページ、対象外投稿タイプを不変に保った。無関係assignmentと両sourceを保持 |
| MT-046 | 成功 | 30件のうち最初の10件完了後にブラウザ更新。自動モーダル表示なし、明示再開1回でpending 20件だけを完了 |
| MT-047 | 成功 | 最初の10件完了後にPageを終了。新しいPageでrunningを確認し、明示再開1回でpending 20件だけを完了 |
| MT-048 | 成功 | 最初の10件後の次requestをabort。成功表示せずrunning／pending 20件を保持し、復旧後の明示再開1回で完了 |
| MT-061 | 成功 | パターンAは一時失敗1回後に自動再試行して継続。パターンBは成功1回後の3連続失敗で停止し、明示再開でpendingだけを完了。権限・nonce・lock・競合・非進捗応答は通信再試行の対象外 |
| MT-062 | 成功 | 更新とPage終了を別環境で実施。どちらもUndo 10件完了・pending 26件から明示再開し、重複なくundone |
| MT-063 | 失敗 | 一方は200、他方は409で安全拒否。開始済みUndoは1件でデータ重複なし。ただし拒否側の`undo_previewed`子Operationが残り、同じ親に子Operationが2件となった |

## 6. MT-043 データ境界

公開、下書き、非公開、予約、承認待ち、ゴミ箱、固定ページ、対象外投稿タイプに、ケース固有のcategory sourceとpost_tag sourceを割り当てた。公開済み標準投稿には無関係なカテゴリーとタグも付与した。

プレビューではsource保持を日本語で表示した。実行後は公開済み標準投稿だけからsourceが外れdestinationが追加され、無関係assignmentを維持した。7種類の対象外オブジェクトは、IDでソートした変更前後JSONが完全一致した。両source termは存在し、2つのtaxonomy Operationは`completed`。4 Itemはcompleted 2・skipped 2、Journal 6件で、重複キーはなかった。

## 7. MT-046～048 実行再開

各ケースで固有接頭辞を持つ完全未使用タグ30件を用意し、1 request最大10件、3 batch以上となる削除を実行した。中断時はcompleted 10件・pending 20件、最終状態はcompleted 30件・pending 0件、Operation `completed`、Journal 30件だった。

更新、Page終了、意図的request abortのいずれでも、処理済みItemを再処理せず、Operation Item、Journal、relationshipを重複生成しなかった。再表示だけでモーダルを開かず、利用者の明示再開1回以降は内部batchごとのクリックを要求しなかった。

## 8. MT-061 Undo通信断

### パターンA: 一時失敗

35投稿の統合を完了して30件超のUndoを用意した。最初のUndo batch成功後、2回目をroute abortし、次の再試行から通信を復旧した。Network記録はUndo batch要求5回で、200応答4回、意図した失敗1回だった。追加クリックなしに`undone`へ到達した。

### パターンB: 継続失敗

別環境で最初のUndo batchを成功させた後、続く3要求をすべてabortした。要求は最初の成功を含む4回で止まり、無限再試行しなかった。子Operationは`undoing`、その子Operation内でcompleted 10・pending 26を保存し、成功表示しなかった。通信復旧後に履歴の「取り消しを再開」を1回押し、pendingだけを処理して`undone`となった。
### 非通信失敗の分類

追加の独立環境で、実ブラウザのUndo batchに対し、サーバー応答形式の権限403とlock 409をDevTools routeで注入した。それぞれ1要求で停止した。Undo modalのnonceを不正値にした実要求も1回で停止した。成功形式だが処理件数が前進しない応答は2要求目で停止した。いずれも通信失敗用の3回再試行を適用せず、Undoを成功表示しなかった。MT-063の実競合409も1要求で停止し、既存のMT-060ではstale Undoが400で開始前拒否されている。

## 9. MT-062 Undo更新・Page終了

更新とPage終了を別フィクスチャ・別環境で実施した。いずれも最初のUndo 10件完了後にクライアントを中断し、子Operation `undoing`とpending 26件を保存した。再開前にモーダルを自動表示せず、履歴詳細の「取り消しを再開」1回から残件を自動処理した。最終的に`undone`となり、35投稿すべてのタグ名ベースassignmentが元操作前と一致した。

## 10. MT-063 不具合

- テストID: MT-063
- 概要: 2タブで同じ元OperationのUndoプレビューを開くと、同じ親を持つ`undo_previewed`子Operationを複数作成できる。
- 再現手順: completed Operationの詳細を2タブで開き、両方でUndoプレビューを開いて、ほぼ同時に「元に戻す」を実行する。
- 期待結果: 子Undo Operationは1件だけで、他方は既開始、lock競合、stale preview等として拒否される。
- 実際の結果: 実行は一方だけが開始・完了し、他方はHTTP 409で安全に拒否された。しかしDBには`undone`子Operationと、未開始`undo_previewed`子Operationの2件が残った。
- 再現率: 1 / 1。
- 影響範囲: 同一元Operationの複数タブUndoプレビュー。通常履歴には未開始行を表示しないため利用者影響は限定的だが、不要な監査行が残り、明示された一意性条件を満たさない。
- 重要度: High。ただしCriticalテストの失敗なので現RCの公開判定はNo-Go。
- データ不整合: なし。
- 重複処理: 開始済みUndo、term、assignment、Item、Journalの重複なし。
- 誤削除: なし。
- 画面／通信: 拒否側は成功表示せず、`admin-ajax.php`が409。内部情報の露出なし。
- 推定原因: `UndoPlanner::preview()`が、同じ親の既存`undo_previewed`を再利用・無効化せず、プレビューごとに子Operationを作成するため。コード変更を伴わない調査上の推定。
- 回避策: Undoは1タブだけでプレビュー・実行する。
- 修正候補: 同じowner・parent・taxonomyの未開始Undoプレビューを原子的に再利用または置換し、開始時は親ごとの一意なstarted Undo制約を維持する。
- 再試験範囲: MT-063、MT-060～062、通常Undo、stale preview、履歴からの再開、preview-only Operation非表示。

## 11. データ比較、Item、Journal

- `before.json`、`interrupted.json`、`after.json`は、term、term taxonomy ID、taxonomy、name、slug、parent、relationship数、投稿タイプ／status、投稿ごとのカテゴリー／タグassignment、Operation、Item、Journalを安定IDでソートして記録した。
- MT-043の対象外オブジェクトは変更前後で一致した。
- MT-046～048はItem 30件・Journal 30件で、`operation_id:item_key`と`operation_id:change_key`が一意だった。
- MT-061／062は各パターンとも元操作＋UndoでItem 72件・Journal 137件、全Item completed、元assignmentへ復元した。
- MT-063はItem 72件・Journal 137件でデータ重複なし。追加の`undo_previewed`子OperationはItem／Journalを持たない。
- 全ケースで1オブジェクト内の同一term relationship重複を検出しなかった。

## 12. Console、Network、PHPログ

- `wp-content/debug.log`: 全環境0行相当。
- PHP／Apacheログ: プラグイン起因のWarning、Notice、Deprecated、Fatal、5xxなし。
- 未処理JavaScript例外: 0件。
- MT-046、048、061、062の`net::ERR_FAILED`: 意図的な更新／abort／Page終了に対応する通信失敗。
- MT-063のHTTP 409: 意図した重複Undo拒否。成功表示や自動再試行なし。
- ログ内URLのWordPress nonceは`[REDACTED]`へ置換した。Cookie、Authorization、パスワード、credential hash、個人情報は証跡へ保存していない。

## 13. 最新集計と判定

| 優先度 | 総数 | 成功 | 失敗 | 保留 |
|---|---:|---:|---:|---:|
| Critical | 44 | 29 | 1 | 14 |
| High | 28 | 18 | 0 | 10 |
| Medium | 0 | 0 | 0 | 0 |
| 合計 | 72 | 47 | 1 | 24 |

判定は**No-Go**。MT-063のCriticalケース失敗に加え、Critical 14件とHigh 10件が保留である。

## 14. 残っているCritical／Highと残存リスク

保留のCritical 14件は、MT-027、MT-034、MT-036、MT-038、MT-039、MT-044、MT-049、MT-050、MT-051、MT-052、MT-053、MT-056、MT-057、MT-068である。

保留のHigh 10件は、MT-006、MT-011、MT-015、MT-025、MT-028、MT-031、MT-035、MT-045、MT-069、MT-070である。正式旧版からの更新、現行WordPress／PHP 8.4、MariaDB、実Safariも今回の対象外である。

## 15. 証跡

証跡は`build/manual-test/recovery/`配下へ保存し、git管理対象外とした。

- `MT-043/`
- `MT-046/`
- `MT-047/`
- `MT-048/`
- `MT-061/pattern-a/`、`pattern-b/`、`non-retry/`
- `MT-062/pattern-a/`、`pattern-b/`
- `MT-063/`

各環境の`environment.json`、変更前後JSON、Operation／Item／Journal、Network、Console、debug／PHPログ、主要スクリーンショット、`test-result.json`を保存した。

## 16. 次に実施すべきテスト

1. MT-063の子Undoプレビュー一意性を修正し、MT-060～063と通常Undo／履歴を回帰する。
2. 残るCriticalを優先し、特にMT-038、039、044、045、049～053、056、057、068を完了する。
3. 残るHighのキーボード／実スクリーンリーダー／200%・400%拡大、1,000タグ操作性、ライフサイクルを実施する。
4. CriticalとHighがすべて成功した後にのみGo判定とPhase状態を再評価する。
