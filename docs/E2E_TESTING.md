# Playwright E2Eテスト

## 目的と役割分担

この基盤は、Term Stewardの主要導線をChromiumで再現可能に確認するためのものです。E2E-001〜008は、管理者認証、検索、入力検証、計画、プレビュー、実行、履歴、Undoと、WP-CLIによる保存状態を確認します。

Playwrightは手動テスト72件の代替ではありません。Safari実機、VoiceOver／NVDAによる実読み上げ、視覚的な品質、全競合・通信障害・互換性マトリクスは、引き続き`docs/MANUAL_TEST_PLAN.md`に従って確認します。

## 必要環境

- Node.js 22以上
- npm
- DockerとDocker Compose v2
- Make、Git

E2Eは既存のWordPress 6.6.2、PHP 8.2、MySQL 8.0、WP-CLI構成を再利用しますが、Compose project名とvolumeは通常開発環境から分離します。

## 初回セットアップ

```sh
npm ci
npm run e2e:install
cp .env.e2e.example .env.e2e
set -a; . ./.env.e2e; set +a
npm run e2e:setup
```

`.env.e2e`はGit対象外です。既定値のままローカルで試す場合、コピーと読み込みは省略できます。パスワード、Cookie、storageStateをコミットしないでください。

## 環境変数

| 変数 | 既定値 | 用途 |
|---|---|---|
| `E2E_BASE_URL` | `http://127.0.0.1:8081` | E2E WordPress URL |
| `E2E_ADMIN_USER` | `e2e-admin` | 管理者ユーザー名 |
| `E2E_ADMIN_PASSWORD` | `e2e-local-password` | ローカル専用パスワード |
| `E2E_ADMIN_EMAIL` | `e2e-admin@example.test` | 管理者メール |
| `E2E_COMPOSE_PROJECT` | `term-steward-e2e` | 専用Compose project名 |
| `E2E_WP_SERVICE` | `wp-cli` | 検証済みWP-CLIサービス名 |
| `E2E_DB_SERVICE` | `database` | 検証済みDBサービス名 |
| `E2E_PORT` | `8081` | ホスト側ポート |
| `E2E_TEST_TIMEOUT` | `60000` | 1テストのtimeout（ms） |
| `E2E_EXPECT_TIMEOUT` | `10000` | assertion timeout（ms） |

安全のため、fixtureとcleanupは`term-steward-e2e`または`term-steward-e2e-`で始まるproject名、localhost URL、固定サービス名だけを受け付けます。fixture側でもサイトURL、ローカル環境種別、サイト名を検証します。

## fixture

`npm run e2e:setup`は専用環境を起動し、WordPressを導入・日本語化し、プラグインを有効化してfixtureを投入します。各specは開始前に同じfixtureへリセットします。登録済みIDだけを削除して再作成し、プラグインのOperation、Item、Journalを専用DB内で初期化するため冪等です。

fixtureは指定された5カテゴリー、5タグ、公開投稿2件、下書き1件を作成します。公開投稿の統合元・統合先・無関係term、下書きの統合元、完全未使用termを含みます。`tools/e2e-state.php`はterm属性、投稿relationship、Operation状態、Item／Journal件数と重複を読み取り専用JSONで返します。

## 実行コマンド

```sh
npm run e2e:setup   # 専用Docker環境とfixtureを準備
npm run e2e:test    # ChromiumでE2E-001〜008を実行
npm run e2e         # setup後に全E2Eを実行
npm run e2e:ui      # Playwright UI mode
npm run e2e:debug   # headed + PWDEBUG
npm run e2e:report  # HTML reportを開く
npm run e2e:clean   # 検証済みE2E projectだけをvolumeごと削除
```

特定ケースは、たとえば次で実行できます。

```sh
npm run e2e:test -- --grep "E2E-006"
```

## レポート、trace、デバッグ

HTMLレポートは`playwright-report/`、テスト出力は`test-results/`です。失敗時だけスクリーンショット、動画、traceを保持します。traceはHTMLレポートから開くか、次で確認できます。

```sh
npx playwright show-trace test-results/<case>/trace.zip
```

認証状態は`playwright/.auth/admin.json`へ保存します。レポートへ認証情報を意図的に添付しません。Console error、pageerror、予期しないHTTP 4xx/5xx、failed requestは共通fixtureが失敗として記録します。意図した403/409はテスト単位の許可リストで指定できます。

## cleanupとよくあるエラー

`npm run e2e:clean`は明示的なE2E Compose projectだけを停止し、そのprojectのvolumeを削除します。通常開発環境には作用しません。

- `E2E_BASE_URL must use ...`: 外部URLへのfixture投入を拒否しています。
- `E2E_COMPOSE_PROJECT must ...`: cleanup範囲がE2E専用と証明できません。
- ポート競合: `E2E_PORT`と`E2E_BASE_URL`を同じ未使用ポートへ変更します。
- ブラウザがない: `npm run e2e:install`を再実行します。
- 認証失敗: `E2E_ADMIN_*`をsetupとtestで同じ値にします。秘密値をログへ貼り付けないでください。
- fixture拒否: 通常開発環境へ向いていないか、サイト識別が変わっています。URLとCompose projectを確認してください。

E2E資産、Node依存、fixture、認証状態、レポート、Docker E2E設定は開発専用です。`.distignore`と配布検査により配布ZIPへ含めません。
