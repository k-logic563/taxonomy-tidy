# MT-063 Undo重複開始 修正・再試験報告

実施日: 2026-09-21（Asia/Tokyo）

## 1. 対象不具合

同じ元Operationに対し、2タブがほぼ同時にUndoプレビューを作成すると、別々の子Undo Operationが作成される競合を対象とした。旧証跡では元Operation ID 1に対して、`undone`の子ID 2と未開始`undo_previewed`の子ID 3が残っていた。

## 2. 根本原因

`UndoPlanner::preview()`は子Operation作成前に元Operation単位の排他を行わず、各要求が独立に子Operationを作成していた。また、既存検索の`started_undos()`は`started_at IS NOT NULL`だけを対象としていたため、作成済みでも未開始の`undo_previewed`を検出できなかった。従来のtaxonomy単位ロックはUndo batch開始時にだけ取得され、termとrelationshipの二重変更は防いだが、プレビュー作成競合は保護していなかった。自動テストも逐次的な通常Undoと実行ロックを主に検証し、同一元Operationへの複数プレビューを検証していなかった。

保存データの確認では、識別子は子Operationの`parent_operation_id`である。旧失敗時は子Operation 2件、子Itemは実行側だけ36件、子Journalは実行側だけ66件で、実際のUndo実行、term再作成、relationship変更は各1回だった。拒否側のbatchは409かつ成功表示なしだった。

## 3. 修正方針

既存`OperationLock`を拡張し、元Operation単位の短い排他区間をプレビュー作成とUndo開始で共有する。ロック取得後に全子Operationを再検索し、安全な既存プレビューは再利用、それ以外の既存状態または既存重複は新規作成せず拒否する。クライアント側の連打防止は補助とし、一意性はサーバー側で保証する。

## 4. サーバー側の一意性保証

プレビューは所有者・Undo可否の事前確認後、元Operationロックを取得し、ロック内で再評価と全子検索を行う。子がなければ1件だけ作成し、同じ所有者・taxonomy・plan hash・state fingerprintの`undo_previewed`が1件ならそのOperationを返す。複数子、別所有者、異常なkind/status、stale fingerprintは安全側で拒否する。プレビュー段階ではItemもJournalも作成しない。

Undo開始も同じ元Operationロック内で子集合と状態を再読込してから、既存のtaxonomy実行ロックを取得する。この順序により、開始時の競合でも有効な子は1件に限定される。完了済み同一子への再送は保存済み結果を返すが、新しい子や変更は作らない。既存重複データは検出して拒否し、自動削除・統合しない。DBスキーマ変更は行っていない。

## 5. ロック方式

ロック名は元Operation IDから導く`undo:<id>`で、既存Operationテーブルの一意な`lock_name`を利用する。別の元Operationは互いにブロックしない。プレビューの排他区間は状態再評価、子検索、必要時の子作成と保存だけで、TTLは60秒。Undo実行では元Operationロックと従来のtaxonomyロックを取得し、batch中に両方を更新する。すべて`finally`で解放し、取得失敗は成功扱いせず409にする。

## 6. 競合時の処理

同時プレビューは許容結果Aを採用し、後着要求が先に保存された同一子Operationを再利用する。同時batchは一方だけが開始し、他方は409と日本語の「別の処理が実行中です。操作履歴から状態を確認してください。」で停止する。409は通信失敗用の最大3回再試行対象にせず、成功表示もしない。実行中、完了済み、再開不能、stale、既存重複にはそれぞれ内部情報を含まない日本語メッセージを返す。

## 7. 変更ファイル

- `src/Infrastructure/Persistence/OperationLock.php`: 元Operation単位ロックを追加。
- `src/Infrastructure/Persistence/OperationRepository.php`: 未開始を含む全子Undo検索を追加。
- `src/Application/Undo/UndoPlanner.php`: ロック後の再確認、既存プレビュー再利用、状態別拒否を実装。
- `src/Application/Undo/UndoWorkflow.php`: Undo開始を同じ元Operationロックで保護し、状態と子集合を再確認。
- `src/Application/Undo/UndoErrorCode.php`: 安全な競合・完了・再開不能・重複エラーを追加。
- `src/Admin/HistoryPage.php`、`src/Admin/Page.php`: 競合HTTP statusと日本語表示、依存注入を更新。
- `assets/js/history.js`: プレビュー連打防止と拒否後のエラー位置へのフォーカスを追加。
- `tests/Integration/UndoWorkflowTest.php`、`tests/Integration/HistoryPageTest.php`、`tests/Integration/AdminScriptTest.php`: 回帰テストと構築コードを更新。
- `docs/MANUAL_TEST_PLAN.md`: MT-063、集計、判定、既知リスクを実結果に更新。
- `docs/MANUAL_TEST_UNDO_CONCURRENCY_RETEST_REPORT.md`: 本報告を追加。

## 8. 追加・更新したテスト

同じ元Operationへの連続プレビューが同じ子を返すこと、プレビュー時のItem/Journalが0件であること、別の元Operationは独立すること、同一元Operationロック競合を拒否すること、例外経路でロックを解放すること、実行中・完了後に新しい子を作らないこと、完了済み同一子の再送が冪等であること、クライアントの二重送信防止とエラーフォーカスを追加・更新した。実プロセス間の同時性はPHPUnitの逐次テストだけでは再現性が不足するため、2タブの同時要求をMT-063のPlaywright試験で補完した。

既存のnonce、capability、所有者分離、stale preview、状態遷移、Undo batch、履歴、Ajax、OperationLockのテストも全スイートで実行した。テストの削除、skip、期待値緩和は行っていない。

## 9. `make check`結果

成功。PHPCS 61/61、JavaScript lint、PHPUnitおよびWordPress統合テストは112 tests / 1,399 assertionsで成功した。PHP構文、Undo Repository/サービス、OperationLock、状態遷移、Ajax/管理リクエスト、操作履歴、追加競合テストを含む。

## 10. Git commit・作業ツリー状態

ベースcommitは`0f8549adbc48fb4882d6f57af9023f7edc0f4d16`。RCは未コミットの修正を含むdirty worktreeから生成した。既存の未コミット変更と旧手動テストレポートは保持した。

## 11. RC ZIP名

`dist/taxonomy-tidy-0.1.0.zip`。`unzip -t`はエラーなし。主要なプラグインbootstrap、Undo Planner/Workflow、管理画面JavaScriptを含み、`.git`、`tests`、`build`、`docker`、`node_modules`は含まない。

## 12. SHA-256

`6612c00c498387cd76d0cf1b045fb713de1132f2dab264fb6726fa939718e5ba`

## 13. 実ブラウザ環境

WordPress 6.6.2、PHP 8.2.28、MySQL 8.0.46、Chromium 153.0.8010.12、ja-JP、Asia/Tokyo、`WP_DEBUG`/`WP_DEBUG_LOG`有効。ソースをマウントしない破棄可能なDocker環境へ、WordPress管理画面からRC ZIPを新規アップロードして有効化した。Firefox/WebKitは今回のMT-063再試験では未実施。

## 14. MT-063再試験結果

成功。2タブから同時にプレビューPOSTを送り、両方とも200で同じ子Operationを参照した。続いてUndo batchを同時送信し、一方は200、他方は409となった。409側は成功表示せず、日本語の競合理由を表示した。完了後、元履歴にはUndoボタンがなく再Undo不可だった。証跡は`build/manual-test/undo-concurrency/`に保存し、旧失敗証跡は変更していない。

## 15. 子Undo Operation数

1件。元Operation ID 1に対して子ID 2だけが存在し、最終statusは`undone`。未開始`undo_previewed`の余剰子はない。

## 16. Item数

子Operationは36件で、すべて完了。全体は元Operation 36件と子Operation 36件の計72件で、同じ子への重複作成はない。プレビュー直後の子Itemは0件で、開始時に1回だけ作成された。

## 17. Journal数

子Operationは66件、元Operationは71件、全体137件。子側の変更記録に重複はない。

## 18. Undo実行回数

1回。Networkでは同時開始の一方だけが処理し、もう一方は409。成功側は有界batchを継続し、最終的に`undone`へ1回だけ到達した。

## 19. データ比較結果

Undo前後のassignmentをterm名で正規化して比較し、元操作前と一致した。termは重複再作成されず、relationshipの重複変更もない。無関係なassignmentにも差分はない。

## 20. MT-053、MT-055～064の回帰結果

| ケース | 新RCでの結果 | 備考 |
|---|---|---|
| MT-053 | 部分確認 | 履歴表示とdraft/preview非表示を確認。所有者分離の全期待結果は今回単独では未完了。 |
| MT-055 | 成功 | 名称を戻し、slug/relationshipを維持。 |
| MT-056 | 部分確認 | 統合Undoと既存destination保持を確認。全境界の再確認は未完了。 |
| MT-057 | 部分確認 | 複数カテゴリーUndoを確認。全assignmentの独立した証跡確認は未完了。 |
| MT-058 | 成功 | 35投稿、4 batchを追加クリックなしで完了。 |
| MT-059 | 成功 | Undo前の管理者変更を競合として保持。 |
| MT-060 | 成功 | stale previewを開始前に拒否し、変更0件。 |
| MT-061 | 部分確認 | A: 一時失敗1回後に自動完了、B: 3回失敗で停止後に明示再開成功。Cの非再試行系はfixture準備失敗で新RC再確認未完了。 |
| MT-062 | 未完了 | Docker/Playwrightハーネス起動・遷移の不調により、新RCで更新・タブ終了の再開を完遂できず。既存計画上の過去成功記録は変更しない。 |
| MT-063 | 成功 | 同時プレビューは同じ子を再利用、同時実行は1回だけ。 |
| MT-064 | 成功 | Undo終端表示、日本語件数、閉じるだけのfooterを確認。 |

コード読解だけで成功にはしていない。全期待結果を確認できなかったケースは部分確認または未完了とした。`docs/MANUAL_TEST_PLAN.md`の保留ケースを今回の部分確認だけで成功へ変更していない。

## 21. Console、Network、PHPログ

Consoleに未処理JavaScript例外はない。409のresource error表示は意図した競合応答である。Networkに予期しない4xx/5xxはなく、同時batchの200/409と後続成功batchを確認した。`debug.log`にはWordPress Coreのテーマ更新がWordPress.orgへ接続できなかった警告1件があるが、プラグイン起因のWarning、Notice、Deprecated、Fatalはない。ApacheのFQDN起動警告以外にプラグイン起因のPHPエラーはない。

## 22. 最新集計

`docs/MANUAL_TEST_PLAN.md`を再集計した結果、Criticalは成功30・失敗0・保留14、Highは成功18・失敗0・保留10、全体は成功48・失敗0・保留24である。総数72件、未実施0件。

## 23. 残っているCritical

14件: MT-027、MT-034、MT-036、MT-038、MT-039、MT-044、MT-049、MT-050、MT-051、MT-052、MT-053、MT-056、MT-057、MT-068。

## 24. 残っているHigh

10件: MT-006、MT-011、MT-015、MT-025、MT-028、MT-031、MT-035、MT-045、MT-069、MT-070。

## 25. Go／No-Go判定

`No-Go`を維持する。MT-063のCritical失敗は解消したが、Critical 14件とHigh 10件が保留である。Phase 7は`In progress`、Phase 8は`Blocked`のままで、いずれも`Completed`へ変更しない。

## 26. 次に実施すべきテスト

最優先は、新RCで未完了となったMT-061CとMT-062 A/Bを安定した破棄可能環境で再実施し、非再試行応答、更新、タブ終了、pendingのみの再開、Item/Journal非重複を確認すること。続いて、部分確認に留まるMT-053、MT-056、MT-057の全期待結果を独立証跡で確認する。その後、残るCritical 14件、High 10件を計画順に実施し、特にMT-068、MT-069、MT-070は実キーボード、表示倍率、スクリーンリーダーで確認する。

## 結論

MT-063の根本原因を修正し、サーバー側の元Operation単位ロック、ロック後の再検索、既存プレビュー再利用により、新しい子Undo Operationの重複を防止した。Chromiumの2タブ同時試験では子Operation 1件、子Item 36件、子Journal 66件、Undo実行1回で、term/assignment重複なしを確認した。残る保留と新RC回帰未完了があるため、リリース判定は変更しない。
