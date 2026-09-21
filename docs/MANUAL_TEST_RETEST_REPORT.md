# Taxonomy Tidy 0.1.0 MT-033 / MT-064 再試験報告

## 1. 結論

MT-033とMT-064の根本原因を修正し、`make check`が成功した同じ作業ツリーから新しいRC ZIPを生成した。ソースをマウントしない新規Docker WordPress環境へ管理画面からZIPをアップロードし、Chromiumで両ケースと関連回帰を再試験した。

再試験はMT-033、MT-064とも成功し、失敗は0件になった。一方、今回の対象外である保留31件は変更していない。そのため最終判定は**No-Go**、Phase 7は`In progress`、Phase 8は`Blocked`のままとする。

## 2. 修正対象と根本原因

### MT-033

`assets/js/plan-board.js`がプレビュー開始時のbutton要素そのものを`opener`として保持していた。プレビュー応答で`#taxonomy-tidy-board-content`がAjax置換されるとその要素はDOMから切り離され、終了処理の`opener.isConnected`条件を満たさないため、フォーカス復帰が行われなかった。

### MT-064

`assets/js/history.js`は`undone`、`undo_partial_failed`、`failed`を検出していたが、終端後に`setModalLocked(false)`を実行するだけだった。そのためプレビュー用の見出しとフッターが残り、「元に戻す」が再有効化されていた。

## 3. 修正内容

- プレビューの起点を`name`/`value`等から安定セレクタとして記録し、終了時に現在DOMの同一操作ボタンを再取得するようにした。見つからない場合は操作計画見出し、操作計画タブの順で安全にフォーカスする。
- Undo終端時は見出しを「取り消し結果」へ変更し、日本語の状態と完了・失敗・残件数を表示し、実行formをDOMから除去してフッターを「閉じる」1つだけにした。
- 通信中断または再開が必要な状態は完了表示にせず、中断文言と「閉じる」だけを表示し、操作履歴から再開する導線を維持した。
- 結果モーダルを閉じた後は操作履歴を再読込し、履歴タブへフォーカスを復帰する。
- MT-058ハーネスの終端文言アサーションを現行UIの「取り消し済み」と「残り：0件」に合わせた。MT-064の証跡名をテストIDと一致させた。

API、DBスキーマ、Operation状態モデル、バッチ処理、taxonomyデータの変更内容は変更していない。

## 4. 変更ファイル

- `assets/js/plan-board.js`
- `assets/js/history.js`
- `src/Admin/Page.php`
- `tests/Integration/AdminScriptTest.php`
- `docs/MANUAL_TEST_PLAN.md`
- `docs/MANUAL_TEST_RETEST_REPORT.md`
- `build/manual-test/`配下のローカル手動ハーネス（git管理対象外）

元の`docs/MANUAL_TEST_REPORT.md`は旧RCの記録として上書きしていない。

## 5. 自動テスと静的検証

- `make check`: 成功
- PHPCS: 61 / 61 files成功
- JavaScript lint: 成功
- PHPUnit / WordPress統合テス: 108 tests、1,380 assertions成功
- PHP構文チェック: PHPUnit/PHPCS用PHP環境で成功
- `git diff --check`: 成功

`AdminScriptTest`に、Ajax再描画後の起点再取得、切り離された要素の非優先化、安全なフォーカスフォールバック、Undo終端状態の判定、結果見出し、実行フッターの置換を固定する回帰チェックを追加した。

## 6. RC ZIPと作業ツリー

| 項目 | 値 |
|---|---|
| Git HEAD | `0f8549adbc48fb4882d6f57af9023f7edc0f4d16` |
| 作業ツリー | MT-033/064修正、テス、ドキュメントが未コミット。作業開始時からの`docs/MANUAL_TEST_PLAN.md`と`docs/MANUAL_TEST_REPORT.md`を保持 |
| RC ZIP | `dist/taxonomy-tidy-0.1.0-mt033-mt064-rc2.zip` |
| SHA-256 | `1bb18c904bd05993e865c10acc3b2347087e6e4361bc81070f92843d0dc5b0be` |
| ZIP整合性 | `unzip -t`成功 |

## 7. 実ブラウザ環境

| 項目 | 値 |
|---|---|
| Docker Compose project | `taxonomy-tidy-retest-rc2-20260920` |
| 導入方法 | 新規専用volume、ソースマウントなし、WordPress管理画面からRC ZIPをアップロード・有効化 |
| WordPress | 6.6.2 |
| PHP | 8.2.28 |
| DB | MySQL 8.0.46 |
| 言語 / タイムゾーン | 日本語 / Asia/Tokyo |
| 主試験ブラウザ | Chromium 153.0.8010.12 |
| 実施日時 | 2026-09-20 20:38 JST |

Firefox 155.0とPlaywright WebKit 26.6の実行バイナリは確認したが、今回のMT-033/064全手順はChromiumでのみ実施した。WebKitを実機Safari確認済みとは扱っていない。

## 8. MT-033再試験

**結果: 成功**

- キャンセル、右上の閉じる、Esc、背景クリックの4手段で閉じた。
- すべてで現在DOMの`plan_command=preview_all`ボタンが`document.activeElement`になった。
- Ajax置換前後の起点識別情報と`isConnected`、終了後のactive状態を`MT-033-focus.json`に記録した。
- 起点ボタンを意図的にDOMから削除した場合は、切り離された旧要素ではなく操作計画タブへフォーカスした。
- フォーム状態、計画、ページ、スクロールを閉じる操作で変更せず、再読込や自動再表示も発生しなかった。

証跡: `build/manual-retest-rc2/evidence/results-core-workflows.json`、`MT-033-after-cancel.png`、`MT-033-after-close.png`、`MT-033-after-escape.png`、`MT-033-after-backdrop.png`、`MT-033-focus.json`、`MT-033-fallback.json`

## 9. MT-064再試験

**結果: 成功**

- Undo開始後はボタンがdisabledと`aria-busy=true`になり、実行中の閉じる・キャンセル・Escを無効化した。
- `undone`終端後は見出しが「取り消し結果」に変わり、「取り消し済み」と進捗・成功・失敗・残件数を文字で表示した。
- 「元に戻す」はDOMから除去され、フッターは「閉じる」のみとなった。終端後にUndo requestは増加しなかった。
- 結果を閉じると操作履歴が最新化され、「取り消し済み」が表示され、履歴タブへフォーカス復帰した。完了モーダルは再表示されなかった。
- サーバーが返す`undo_partial_failed`と`failed`も終端結果として、`undoing`等の非終端状態は中断・履歴からの再開として分岐することを自動回帰で固定した。

証跡: `build/manual-retest-rc2/evidence/results-undo-workflows.json`、`MT-064-before.png`、`MT-064-running.png`、`MT-064-result.png`

## 10. 関連回帰とスモーク

- MT-030: 成功。明示操作でのみ開き、閉じた後と再読込後に自動表示しない。
- MT-034の関連範囲: 実行開始時のロックと重複処理なしを確認。高速連打の全パターンは従来どおり保留。
- MT-035の関連範囲: dialog ARIA、初期フォーカス、Tab循環を確認。実スクリーンリーダー確認は従来どおり保留。
- MT-036: 成功。実行中はclose/cancelがdisabledで、Esc後もモーダルを維持。
- MT-045: 成功。実行完了後の日本語結果と自動再表示なしを確認。
- MT-055: 成功。名称変更Undoでnameを復元し、slug/relationshipを維持。
- MT-058: 成功。35投稿のUndoを4回の有界batch requestで自動継続し、追加クリックなしで「取り消し済み」、残り0件に到達。
- MT-060: 成功。古いUndoプレビューは400で開始前拒否し、変更を上書きしない。
- MT-068の関連範囲: モーダルのTab/Esc、フォーカス復帰は成功。ケース全体は従来どおり保留。

スモークで、カテゴリー一覧、タグ一覧、計画追加、プレビューのキャンセル、名称変更の実行、未使用termの複数削除、統合、操作履歴、Undo、再読込後のモーダル非表示を確認した。

## 11. Console、Network、PHPログ

- 未処理JavaScript例外: 0件
- 予期しないrequest failure / 5xx: 0件
- MT-060のadmin-ajax 400: 古いUndoプレビューの意図した拒否
- `wp-content/debug.log`: ファイル未生成（0行相当）

## 12. 残件と判定

| 結果 | 件数 |
|---|---:|
| 成功 | 41 |
| 失敗 | 0 |
| 保留 | 31 |

失敗は解消したが、通信断やタブ終了からのUndo再開、2タブ競合、全assignmentの厳密比較、VoiceOver/NVDA、200%/400%拡大などの保留31件が残る。よって**No-Go**とし、Phase 7/8をCompletedにしない。

次は、保留されたCritical 21件を優先し、特にMT-043、MT-046～048、MT-061～063、MT-068、MT-070を実施する。
