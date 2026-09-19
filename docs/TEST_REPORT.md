# Taxonomy Tidy 0.1.0 Phase 8 テストレポート

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
| `make check` | 成功。PHPCS 60/60、JavaScript lint、99 tests / 1,313 assertions |
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

Phase 1〜6の骨格、永続化、正確な一覧、計画・プレビュー、バッチ実行、履歴・Undoはコードと99件の統合テストで確認した。Phase 7の4タブ、アコーディオン、ページネーション、モーダル、ARIA、日本語カタログ、入力不要の統合先選択も実装・自動テストが存在する。ただしPhase 7文書のとおり実ブラウザ確認が未完了で、状態は`In progress`のままである。

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
