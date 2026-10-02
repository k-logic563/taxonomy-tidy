# Taxonomy Tidy 0.1.0 残存High 10件 完了試験レポート

> 旧開発名称：Taxonomy Tidy／現製品名称：Term Steward。以下はリブランド前の試験証跡です。

## 2026-09-29 MT-006追補

`REQUIREMENTS.md`へ、アンインストール時に操作履歴、Operation Item、Change Journalの3テーブルとDB schema version optionを保持する対象、理由、再インストール後の履歴再利用、利用者告知を正本化した。`README.md`とWordPress.org向け`readme.txt`にも、WordPress管理画面からプラグインを削除してもデータがデータベースへ残ることを明記した。2026-09-26の実ブラウザ試験では、無効化、再有効化、同版上書き、管理画面削除、再導入後の保持・履歴再利用が成功済みであるため、MT-006を保留から成功へ変更する。

最新集計はCritical成功44・失敗0・保留0、High成功26・失敗1・保留1、全72件成功70・失敗1・保留1。残件はMT-069とMT-070で、Criticalへの波及は確認されていない。製品コードのアンインストール動作は変更していない。

## 2026-09-27 最終判定での扱い

最新集計はHigh成功25・失敗1・保留2で変わらない。2026-09-27の最終リリース判定では、Highの失敗・保留はCriticalへ影響しない限り0.1.0の公開阻害事項としない基準が指定されたため、MT-069、MT-006、MT-070を個別に再評価した。

- MT-069は操作履歴一覧の320 CSS pxにおけるページ横あふれであり、表領域はキーボードフォーカス可能で、Criticalの主要操作、データ安全性、PHP／JavaScriptエラーへ波及しない。
- MT-006は実際の無効化・再有効化・上書き・アンインストール・再導入と履歴保持が成功済みで、残件は上位文書における保持方針の確定である。
- MT-070は実スクリーンリーダーの環境制約による未確認で、DOM／キーボード／モーダルのCritical経路は別ケースで成功済みである。

したがって、この3件は既知の残存リスクおよび公開後確認事項として維持するが、最終判定は`Go`、Phase 7／8は`Completed`とする。以下の`No-Go`は2026-09-26時点の旧判定として保持する。

## 結論

2026-09-26に、保留だったHigh 10件を現行RC ZIPの独立Docker環境とChrome for Testingで再試験した。結果は成功7件、失敗1件、保留2件。High全体は成功25件・失敗1件・保留2件、全72件は成功69件・失敗1件・保留2件となった。

- 失敗: MT-069。320 CSS pxの操作履歴一覧で、表が明示スクロール領域へ閉じず、ページ全体が329 px横移動できる。
- 保留: MT-006。実際の保持・再利用動作は成功したが、アンインストール方針の正本が未確定。
- 保留: MT-070。VoiceOver／NVDAによる実読み上げを実施できていない。

製品コードは修正していない。Phase 7は`In progress`、Phase 8は`Blocked`、公開判定は **No-Go** とする。コミット、push、GitHub Release、WordPress.org公開、外部アップロードは行っていない。

## 事前確認と対象

- 読了資料: `AGENTS.md`、`REQUIREMENTS.md`、`docs/IMPLEMENTATION_PLAN.md`、`docs/MANUAL_TEST_PLAN.md`、`docs/MANUAL_TEST_CRITICAL_COMPLETION_REPORT.md`、`docs/TEST_REPORT.md`、`docs/RELEASE_CHECKLIST.md`、関連README／設計資料／既存テスト。
- 対象ケース: MT-006、011、015、025、028、031、035、045、069、070。
- 対象外: 製品コード修正、後続フェーズ着手、公開操作。
- 開始時Git HEAD: `b8de258b64d46f6a5990cc8f0933292e44c817a0`
- 開始時作業ツリー: 既存の未コミット変更あり。`src/`、`tests/`、`languages/`、`bin/run-tests.sh`、Phase文書等の差分を保持し、破棄・上書きしていない。
- 試験開始: 2026-09-26 20:06:21 JST
- 試験終了: 2026-09-26 20:42:09 JST

## RCと環境

- 対象ZIP: `dist/taxonomy-tidy-0.1.0.zip`
- SHA-256: `528362390fa5183d53fd6e938786351ecc4ce5f71f51a10e637c89bbbacd1610`
- `unzip -t`: 成功。
- checksum照合: 成功。
- ZIP展開物と現行ソース: `src/`、`assets/`、`bootstrap/`、`languages/`、プラグイン本体、README、CHANGELOG、LICENSEを比較し差分なし。
- ホスト: macOS 27.0 (26A428), arm64。
- Docker 29.7.2、Docker Compose 5.5.1。
- 各試験環境: WordPress 6.6.2、PHP 8.2.25、MySQL 8.0.46、`ja`、`Asia/Tokyo`、`WP_DEBUG=true`、`WP_DEBUG_LOG=true`、`WP_DEBUG_DISPLAY=false`。
- ブラウザ: Chrome for Testing / Chromium 153.0.8010.12。Firefox 155.0とPlaywright WebKit build 2359の存在は確認したが、今回の10件はChromiumで実施した。Safari 27.0は起動できたが、自動操作権限を得られなかった。

ソースをWordPressへマウントせず、管理画面からRC ZIPを導入した4環境を使用した。同時稼働は最大2環境までとし、各グループ終了後に停止した。

| Group | Compose project | Port | 主なfixture | ケース |
|---|---|---:|---|---|
| A | `taxonomy-tidy-high-a-20260926` | 18461 | manual＋完了履歴 | MT-006 |
| B | `taxonomy-tidy-high-b-20260926` | 18462 | large（1,000タグ） | MT-011、015 |
| C | `taxonomy-tidy-high-c-20260926` | 18463 | manual | MT-025、028、031 |
| D | `taxonomy-tidy-high-d-20260926` | 18464 | recoveryケース＋manual | MT-035、045、069 |

## ケース別結果

| Case | 判定 | 期待と実績 | 主な証跡 |
|---|---|---|---|
| MT-006 | 保留 | 無効化／再有効化、同版ZIP上書き、管理画面削除、再導入で、3テーブル、schema option、完了履歴を保持・再利用。DBバックアップも取得した。ただし`REQUIREMENTS.md`は3テーブル／schema option保持、履歴再利用、利用者告知を規定しておらず、期待結果の正本を確定できない。 | `group-a/results.json`、`MT-006-before-uninstall.sql`、`MT-006-*.png` |
| MT-011 | 成功 | category 50件、tag 100件で複合条件と2ページ目からリセット。taxonomyと表示件数だけを維持し、条件要約「条件なし」、データ不変。 | `group-b/results.json`、`MT-011-*.png` |
| MT-015 | 成功 | 0件時の日本語空状態、pagination／選択なし。1,000タグで20行、統合先1,000件、DOM 1,797要素。cold load 757.51 ms、最大応答930.21 ms、Long Task 0件、操作取りこぼしなし。 | `group-b/results.json`、`MT-015-*.png` |
| MT-025 | 成功 | 未選択、空名、同名、複数rename、不正slug、空白名をセクション内の日本語エラーで拒否し、修正先へフォーカス。修正後の再送信成功、データ不変。 | `group-c/results.json`、`MT-025-*.png` |
| MT-028 | 成功 | rename重複、rename＋merge、rename＋delete、merge＋delete、自己統合、taxonomy跨ぎ、親→子孫を拒否。有効な既存draftを保持。 | `group-c/results.json` |
| MT-031 | 成功 | rename、明示slug、merge、delete、親source保持について、名称、件数、削除／保持理由と最小表示を照合。内部ID／hash／fingerprint／statusを非表示。キャンセルで不変。 | `group-c/results.json`、`MT-031-preview.png` |
| MT-035 | 成功 | preview／Undoの両モーダルでdialog名、フォーカス循環、背景非操作、scroll lock、Esc、起点への復帰を確認。実行中／Undo中は閉鎖・二重操作を拒否。 | `group-d/results.json`、`MT-035-undo-result.png` |
| MT-045 | 成功 | completed、partial_failed、failedを区別。partial_failedはItem 35成功・1失敗、Journal 70。failedは開始可能Item 0、Journal 0でterm／relationship不変。再読み込み／タブ移動で旧モーダルを再表示しない。 | `group-d/results.json`、`MT-045-*.png`、`MT-045-*-after.json` |
| MT-069 | 失敗 | category／tag一覧は320 CSS px、category一覧は640 CSS pxでページ横あふれなし。操作履歴一覧は320 CSS pxでdocument幅649 px、`window.scrollX=329`となり、表の横スクロールがページ全体へ漏れた。headless Chromiumでは標準拡大shortcutが倍率を変更せず、実200%も未確認。 | `group-d/results.json`、`MT-069-*.png` |
| MT-070 | 保留 | Safari 27.0とVoiceOverは存在するが、`safaridriver --enable`は管理者認証不可、SafariへのApple Eventsは`-1743`で拒否。VoiceOverによる実読み上げを操作・記録できず、WebKit／DOM検査を代替成功にしていない。 | 本レポート、実行時コマンド記録 |

証跡ルートは`build/manual-test/high-completion/evidence/`。これは配布対象外のgitignored試験生成物である。

## データと永続化の確認

- MT-006の開始前はOperation／Item／Journalが各1件、schema versionは`1`。無効化、再有効化、上書き、アンインストール、再導入後も件数と履歴を維持した。
- MT-025／028／031は拒否またはキャンセルまでとし、term、slug、relationship、履歴を変更していない。
- MT-045 partial_failedは36 Item中35 completed・1 failed、Journal 70。競合として注入した管理者変更を保持した。
- MT-045 failedはOperationだけを`failed`で保存し、Item／Journal 0。2つのsource termと競合relationshipを保持した。
- 対象外オブジェクト、無関係term assignment、taxonomy境界を越える変更は検出されていない。

## ログと差異

- 全Groupのブラウザ監視で未処理JavaScript例外、request failure、HTTP 400以上は0件。
- WordPressコンテナログを開始後範囲で検索し、PHP Fatal／Warning／Notice／Deprecated、uncaught error、stack traceは0件。
- `WP_DEBUG_LOG`は有効だが、4環境とも`wp-content/debug.log`自体が生成されなかった。PHPメッセージが書かれていない状態としてcopy errorも証跡へ保存した。
- 試験ハーネスの途中失敗は、非表示select操作、fixture再利用、failed結果モーダルが自動で閉じるという誤仮定によるもの。製品失敗には数えず、最終`results.json`と保存DB状態で再確認した。

## 問題の分類と推定原因

| 項目 | 分類 | 推定原因／必要対応 |
|---|---|---|
| MT-069 | 製品コード不具合・公開阻害 | `.taxonomy-tidy-history-table`の`min-width: 720px`に対し、`.taxonomy-tidy-table-scroll`が`max-width: 100%`だけで狭幅時のused widthを拘束できず、表のmin-content幅がページへ漏れる可能性が高い。製品コードは未修正。修正後に320 CSS px、実200%、400%相当、履歴詳細／モーダルを回帰する。 |
| MT-006 | 仕様・文書の未確定 | `README.md`等は3テーブル保持を述べるが、上位正本`REQUIREMENTS.md`にテーブル、schema option、履歴再利用、利用者告知がない。方針を明記してから再判定する。 |
| MT-070 | 試験環境制約／未検証 | macOSのアクセシビリティ、Automation、Safari WebDriver権限がない。権限のある実機でVoiceOverまたはNVDAの読み上げを実施する。 |
| Safari smoke | 残存互換性リスク | Safari自体は起動したが制御・検証不可。WebKit成功をSafari実機成功とは扱わない。 |

## 検証結果

- `make check`: 成功。
  - PHPCS: 62 / 62 files。
  - JavaScript lint: 成功。
  - PHPUnit／WordPress統合: 119 tests / 1,466 assertions。
  - Ajax PHPUnit: 1 test / 6 assertions。
- `git diff --check`: 成功。
- 新しい版管理対象テストは追加していない。試験専用ハーネスと証跡だけを`build/manual-test/high-completion/`へ追加した。

## 公開判断

Phase 7とPhase 8の完了条件は満たしていない。MT-069の製品不具合が1件あり、MT-006とMT-070が保留であるため、現RCは **No-Go**。次に必要なのは、(1) MT-069の限定修正と影響範囲回帰、(2) アンインストール保持方針の正本化とMT-006再判定、(3) VoiceOver／NVDA実機でのMT-070、(4) Safari実機スモークである。
