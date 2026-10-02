# Undo completion 実ブラウザ試験レポート

> 旧開発名称：Taxonomy Tidy／現製品名称：Term Steward。以下はリブランド前の試験証跡です。

## 1. 最新結論（2026-09-26）

- 対象RC: `dist/taxonomy-tidy-0.1.0.zip`
- SHA-256: `48025c0f330f5ed829e103448c92a5e25aea9cad08d72f40f030174887d798e2`
- MT-061: **成功**（A、B、Cの全条件）
- 判定: **No-Go**（失敗0件、保留21件）
- Phase 7: `In progress`のまま
- Phase 8: `Blocked`のまま

不正nonceは、非再試行JSON 403と再読み込み／再ログイン案内を返すよう修正した。旧MT-061C-4は、最初の正常batch後に競合を注入していたため開始前競合ではなくC-4bだった。C-4a（開始前）は409で無変更停止、C-4b（開始後）は競合Itemだけ失敗させて`undo_partial_failed`となることを別々に確認した。したがって、旧C-4の200継続は製品不具合ではなく試験時点の分類誤りである。

| ケース | 結果 | 修正後RCでの確認 |
|---|---|---|
| MT-061A | 成功 | 一時通信失敗1回後、自動再試行で追加操作なく完了 |
| MT-061B | 成功 | 3回失敗で安全停止し、明示再開1回でpendingだけを完了 |
| MT-061C-1 | 成功 | capability 403、条件request 1回、自動再送0回 |
| MT-061C-2 | 成功 | nonce 403、条件request 1回、自動再送0回、再読み込み／再ログイン案内、データ不変 |
| MT-061C-3 | 成功 | lock 409、条件request 1回、自動再送0回、重複なし |
| MT-061C-4a | 成功 | 開始前競合409、子`undo_previewed`、子Item／Journal 0件、管理者変更保持 |
| MT-061C-4b | 成功 | バッチ間競合はHTTP 200で安全項目を継続し、成功35／失敗1、`undo_partial_failed`、管理者変更保持 |
| MT-061C-5 | 成功 | 非進捗応答1回で停止、自動再送0回、pending 26件を保持 |

同じRCでMT-058～060、MT-062A/B、MT-063 Chromiumを含むUndo回帰も成功した。MT-063 Firefox/WebKitはロック・子Operation・一意性コードに変更がないため、2026-09-25の成功証跡を再利用した。全ケースで予期しないConsole／Network／PHPエラーは0件だった。

## 2. 2026-09-26の自動検証とRCスモーク

- `make check`: PHPCS、JavaScript lint、通常PHPUnit 114 tests / 1,421 assertions、Ajax PHPUnit 1 test / 6 assertionsに成功
- `make dist`、`unzip -t`、SHA-256照合に成功
- ソース未マウントの新規WordPress環境へRC ZIPを導入し、名称変更、2-source統合、15タグ削除、12カテゴリー削除を確認
- 30件超の複数Item UndoはMT-058で利用者の1回の操作から4 Ajax batchで完了
- 最終スモークの`debug.log`は0行、予期しない実行時エラーは0件

以下は2026-09-25初回試験時点の記録であり、C-2と旧C-4の判定は上記追補で置き換える。

## 3. 2026-09-25初回試験の環境と方法

- WordPress 6.6.2、日本語、タイムゾーンAsia/Tokyo
- PHP 8.2.28、MySQL 8.0.46
- Chromium 153.0.8010.12
- Firefox 155.0（公式Playwright Linuxコンテナ、制御ポートはlocalhostのみに公開）
- WebKit 26.6 / Safari 605.1.15相当
- 各ケースは固有Docker Compose project、DB volume、ポートを使用し、ソースをマウントしない独立環境で実行
- WordPress管理画面からRC ZIPをアップロードして有効化
- `WP_DEBUG=true`、`WP_DEBUG_LOG=true`、`WP_DEBUG_DISPLAY=false`
- ケース開始直前にDB dumpを作成し、ケースごとにOperation、Item、Journal、term、relationship、投稿assignmentをJSON保存
- MT-061C各サブケースは別DB状態で実施し、通信制御を`taxonomy_tidy_undo_batch`だけに限定

Gitの通常コマンドは未同意のXcodeライセンスにより実行不能だったため、`.git/refs/heads/main`から対象commit `b8de258b64d46f6a5990cc8f0933292e44c817a0`を記録した。RC ZIPのchecksumとZIP整合性は別途確認した。

## 4. 2026-09-25初回試験のケース別結果

| ケース | 結果 | 確認内容 | 主証跡 |
|---|---|---|---|
| MT-053 | 成功 | draft、通常preview、Undo preview、他管理者操作を除外。completed、partial_failed、failed、undoing、undoneを新しい順・WordPress時刻で表示し、taxonomy、処理、対象件数、変更件数、結果、Undo可否、所有者分離、直接URL拒否、内部情報非表示を確認 | `build/manual-test/undo-completion/MT-053/` |
| MT-056 | 成功 | 削除済みsourceを新IDで元投稿だけへ再作成。元から存在するdestinationとUNRELATEDを維持し、子Undo Operation 1件でundone。Item・Journal・assignment重複なし | `build/manual-test/undo-completion/MT-056/` |
| MT-057 | 成功 | 12 sourceカテゴリーを1回の利用者操作・3 batchでUndo。親カテゴリー、全assignment、無関係分類を復元し、重複なし | `build/manual-test/undo-completion/MT-057/` |
| MT-061C-1 | 成功 | 最初の10件後に権限を外し、403。条件request 1回、自動再送0回。日本語権限エラー、データ不変 | `build/manual-test/undo-completion/MT-061C-1/` |
| MT-061C-2 | **失敗** | 不正nonceは403、条件request 1回、自動再送0回、データ不変。ただしUIが汎用エラーで、再読み込みまたは再認証が必要と判断できない | `build/manual-test/undo-completion/MT-061C-2/` |
| MT-061C-3 | 成功 | 最初の10件後に親Operation lockを競合させ409。条件request 1回、自動再送0回。解除後、既存の子Undoから明示再開しundone。子Operation追加なし | `build/manual-test/undo-completion/MT-061C-3/` |
| MT-061C-4 | **失敗** | 最初の10件後、次batch直前にassignmentを変更。期待した409停止にならず200で継続し、合計4 batch、`undo_partial_failed`（成功35、失敗1）まで進行 | `build/manual-test/undo-completion/MT-061C-4/` |
| MT-061C-5 | 成功 | 最初の10件後、同一進捗の正常JSONを1回返却。条件request 1回、自動再送0回で中断し、undoing・pending 26件を維持 | `build/manual-test/undo-completion/MT-061C-5/` |
| MT-062A | 成功 | 10件後に再読み込み。undoing・pending 26件を保持し、「取り消しを再開」1回から既処理Itemを再実行せずundone | `build/manual-test/undo-completion/MT-062A/` |
| MT-062B | 成功 | 10件後にPageを終了し、新しいPageから履歴を表示。明示再開1回でpendingだけを処理しundone | `build/manual-test/undo-completion/MT-062B/` |
| MT-063 Firefox | 成功 | 2タブ同時プレビューでも子Operation 1件、Undo実行1回。競合側は成功表示せず、完了後の再Undo不可 | `build/manual-test/undo-completion/MT-063-firefox/` |
| MT-063 WebKit | 成功 | Firefoxと同じ重複開始防止、データ・Item・Journal重複なしを確認 | `build/manual-test/undo-completion/MT-063-webkit/` |

## 5. 2026-09-25初回試験のMT-061C request数と不変性

| サブケース | 最初の正常batch | 条件request | 同条件の自動再送 | 条件応答 | 条件後のUndo batch合計 | データ判定 |
|---|---:|---:|---:|---:|---:|---|
| C-1 | 1 | 1 | 0 | 403 | 2 | 最初のbatch後から不変 |
| C-2 | 1 | 1 | 0 | 403 | 2 | 最初のbatch後から不変 |
| C-3 | 1 | 1 | 0 | 409 | 2 | 拒否時不変。明示再開後のみ進行 |
| C-4 | 1 | 1 | 0 | **200** | **4** | 競合後も処理が進行し、Item・Journal・assignmentが変化 |
| C-5 | 1 | 1 | 0 | 200 | 2 | 最初のbatch後から不変 |

Heartbeat、画面取得、履歴取得は上記Undo batch数から除外した。C-4では変更を注入したrequest自体の再送はないが、200応答を受けて後続batchが2回自動送信されたため、正式期待結果の「合計1回で停止」およびデータ不変を満たさない。

## 6. 初回試験の指摘と解決

### UCR-001: 不正nonce時に再読み込み／再認証を案内しない

- 解決: 2026-09-26。明示JSON 403、`retryable: false`、再読み込み／再ログイン案内を実装し、Ajax統合テストとMT-061C-2で確認した。

- 対応ケース: MT-061C-2
- 再現: 30件超Undoの最初のbatch完了後、次の`taxonomy_tidy_undo_batch`のnonceを無効値に置換する。
- 実際: HTTP 403、request 1回、自動再送0回。UIは「取り消し処理を続行できませんでした。」のみ。子Operationは`undoing`、pending 26件、Journal 85件で不変。
- 期待: 再読み込みまたは再認証が必要と分かる日本語案内。
- 推定原因: `check_ajax_referer()`の既定die応答がJSONメッセージを返さず、クライアントが汎用文言へフォールバックする。
- 修正候補: nonce検証を非終了形式で行い、`retryable: false`と再読み込み／再認証案内を含むJSON 403を明示的に返す。
- 必要な回帰: capability 403、nonce 403、通常Undo、通信失敗3回再試行、非JSON 4xx/5xx、履歴からの再開、内部情報非表示。

### UCR-002: Undo途中の状態競合をbatch全体の409として停止しない

- 解決: 製品不具合ではなく試験定義の時点分類誤りとして2026-09-26にクローズ。旧試験は開始後のC-4bで、項目単位競合と`undo_partial_failed`が正しい。開始前のC-4aは別試験で409無変更停止を確認した。

- 対応ケース: MT-061C-4
- 再現: 最初の10件を正常Undo後、次batch直前に対象投稿のdestination assignmentを別管理者相当で削除する。
- 実際: 条件requestはHTTP 200。処理は自動継続して合計4 batchとなり、子Operationは`undo_partial_failed`、Itemはcompleted 71 / failed 1、Journal 136件。UIは「一部のみ取り消し」で、状態再確認案内なし。
- 期待: HTTP 409で即時停止し、そのrequestではUndoを適用せず、新しい管理者変更を保持する。
- 推定原因: fingerprint検証は`undo_previewed`から`undoing`へ遷移する開始時だけで、再開batchでは固定済みItemを個別実行し、競合Itemだけをfailedとして後続Itemを処理する。
- 修正候補: 各継続batchの変更前に、保存済み状態／fingerprintまたは同等の競合条件を再検証し、競合時は非再試行の409でbatch全体を停止する。MT-059のitem単位競合仕様との整合を設計時に明確化する。
- 必要な回帰: MT-059、MT-060、MT-061A～C、MT-062A/B、MT-063、partial_failed/failed、既処理Itemの冪等性、管理者変更保護。

## 7. 2026-09-25初回試験のログ確認

- 全ケースでWordPress `debug.log`にPHP error、warning、notice、deprecated、stack traceなし。
- PHP/Apacheログにアプリケーション由来のfatal、warning、deprecated、noticeなし。
- C-1/C-2の403、C-3の409は意図した拒否としてブラウザconsoleへ記録。
- C-4の最終正式試験ではHTTP 200のため拒否ログなし。これは不具合結果そのもの。
- MT-062Aの`net::ERR_FAILED`は意図的に遮断したrequest。
- FirefoxのHTTPパスワード警告はlocalhost限定の使い捨て試験環境がHTTPSでないことによる環境警告。

## 8. 2026-09-25初回判定への反映

- MT-053、MT-056、MT-057を保留から成功へ変更。
- MT-061A/Bは既存成功を維持。MT-061Cは5件中2件失敗のため失敗。したがってMT-061全体も失敗。
- MT-062はパターンA/Bとも成功を再確認し、成功を維持。
- MT-063はFirefox/WebKitとも成功し、成功を維持。
- 集計はCritical 44件中、成功32、失敗1、保留11。Highは成功18、失敗0、保留10。全体は成功50、失敗1、保留21。
- 他のCritical／High保留とMT-061失敗が残るため、Phase 7/8とGo／No-Goは変更しない。

## 9. 検証上の制約

- Safari実機は未実施。今回の指定範囲はPlaywright WebKitで確認した。
- FirefoxはmacOS用Playwright Firefoxがprofile作成エラーで起動しなかったため、公式Playwright Linuxコンテナで実施した。
- 2026-09-26のMT-061C-3再試験では、製品操作前のページ待機／checkbox操作でPlaywrightが一時タイムアウトした。専用環境を破棄し、ハーネスのDOM操作を安定化して完全新規環境から再実行し成功したため、製品失敗には数えていない。
- 2026-09-25初回時点はホストの`make check`と`git`がXcodeライセンス未同意により起動できなかった。2026-09-26は通常の`make check`と`git diff --check`を実行して成功した。
- 既存のCritical／High保留21件は本試験の対象外であり、解消していない。
