# Taxonomy Tidy ローカル開発・Phase 1検証手順

Phase 1の基準環境はWordPress 6.6.2、PHP 8.2、MySQL 8.0です。PHP、Composer、MySQL、WP-CLIをホストへ個別にインストールする必要はありません。

## 必要な事前ソフトウェア

- Docker Desktop、またはDocker EngineとDocker Compose v2プラグイン
- GNU Make互換の`make`
- Git

次のコマンドが成功することを確認します。

```sh
docker --version
docker compose version
make --version
git --version
```

既定ではホストのTCPポート`8080`を使用します。使用中の場合は`.env`の`WP_PORT`を変更してください。

## Gitの初期化

まだGitリポジトリでない場合は、プロジェクトルートで次を実行します。

```sh
git init
git status --short
```

`.env`、`vendor/`、IDE設定、キャッシュ、テスト・ビルド出力は`.gitignore`の対象です。`composer.lock`は再現可能な依存解決のためコミット対象です。

## Composer lockファイル

初回にDocker上のComposerから`composer.lock`を生成します。

```sh
make composer-lock
```

これは次のコマンドの短縮形です。

```sh
docker compose run --rm composer update --no-interaction --prefer-dist
```

`composer.lock`が存在する通常のインストールでは、依存バージョンを更新せず次を使用します。

```sh
make dependencies
```

`make setup`も、lockファイルがなければDocker上で生成し、存在すれば`composer install`を実行します。

## 初回セットアップ

```sh
cp .env.example .env
make setup
```

`make setup`は次を順番に行います。

1. Composer依存関係のインストール
2. MySQLとWordPressコンテナの起動
3. 未導入の場合のみWordPressのインストール
4. Taxonomy Tidyの有効化

セットアップ後の状態は次で確認できます。

```sh
docker compose ps
docker compose run --rm wp-cli core version
docker compose run --rm wp-cli plugin status taxonomy-tidy
```

## WordPressの起動、停止、再起動

```sh
make up       # WordPressと開発用MySQLを起動
make stop     # コンテナを停止・削除。データvolumeは保持
make restart  # 起動中のWordPressとMySQLを再起動
```

`make stop`後の再起動は`make up`です。

## 管理画面と初期ログイン

既定のURL:

```text
WordPress管理画面: http://localhost:8080/wp-admin/
Taxonomy Tidy:     http://localhost:8080/wp-admin/tools.php?page=taxonomy-tidy
```

`.env.example`の既定ログイン情報:

```text
ユーザー名: admin
パスワード: admin
```

この認証情報はローカル専用です。LANや外部から接続可能な環境では、セットアップ前に`.env`の`WP_ADMIN_PASSWORD`を変更してください。

既存環境で管理者を追加する場合:

```sh
docker compose run --rm wp-cli user create phase1-admin phase1-admin@example.test --role=administrator --user_pass=local-admin-password
```

## プラグインの有効化と無効化

```sh
make activate
make deactivate
docker compose run --rm wp-cli plugin status taxonomy-tidy
```

`vendor/autoload.php`がない場合、プラグインは本体の起動を中断し、管理画面に原因と`composer install`コマンドを示します。復旧コマンドは次のとおりです。

```sh
make dependencies
make activate
```

## PHPUnitとPHPCS

```sh
make test   # 独立したテストDBでWordPress PHPUnit統合テストを実行
make phpcs  # WordPress Coding Standardsを実行
make check  # PHPCSとPHPUnitを順番に実行
```

テスト用DB、WordPress core、WordPress test libraryは開発サイトとは別のDocker volumeを使用します。

## Phase 3表示確認用シードデータ

シード処理はDockerのローカル開発環境からWP-CLI経由でのみ実行できます。`WP_ENVIRONMENT_TYPE`が`local`でない環境では停止します。プラグインの有効化時や管理画面表示時に自動実行されることはありません。

目視確認用のデータを作成します。

```sh
make seed-demo
```

作成件数は、カテゴリー25件、タグ75件、公開済み投稿90件、下書き10件、非公開投稿5件、予約投稿5件、固定ページ5件です。日本語の名前とタイトルを使用し、次の状態を固定して作成します。

- `WordPress`、`wordpress`、`WP`、`ワードプレス`などの表記揺れ
- `JavaScript`、`Javascript`、`JS`、`SEO`、`seo`、`Web制作`、`WEB制作`
- 開発、マーケティング、ECとその子カテゴリー
- 公開済み投稿だけ、公開済み投稿と下書き、下書きだけ、対象外オブジェクトだけで使われるタグ
- WordPress全体で完全に未使用のカテゴリーとタグ
- 1投稿に複数の表記揺れタグがある状態
- 1投稿に統合元候補と統合先候補の両方がある状態

ページング、検索、並び替え、表示速度の確認用データを作成します。

```sh
make seed-large
```

作成件数は、カテゴリー100件、タグ1,000件、公開済み投稿320件、下書き50件、非公開投稿25件、予約投稿25件です。最後の10カテゴリーと100タグは完全未使用、別の10カテゴリーと100タグは対象外投稿だけで使用されます。

同じシードコマンドは何度実行しても同じ内部キーのデータを再利用し、無制限に重複しません。demoとlargeを切り替える場合は、先にクリーンアップしてください。

```sh
make seed-clean
make seed-large
```

クリーンアップは、専用optionへ記録されたIDと、投稿・タームに付けた内部マーカーの両方を照合してから実行します。記録とマーカーが一致しない場合や、シードタームが既存オブジェクトから使用されている場合は、何も削除せずエラーで停止します。既存の投稿、カテゴリー、タグ、デフォルトカテゴリーは削除しません。`make seed-clean`はデータがない状態で再実行しても安全です。

シード実装は`tools/`に隔離され、`.distignore`で配布物から除外されています。ローカル環境以外へコピーまたは実行しないでください。

## 権限確認用ユーザー

許可ユーザーは管理者で確認できます。明示的に追加する場合:

```sh
docker compose run --rm wp-cli user create phase1-authorized phase1-authorized@example.test --role=administrator --user_pass=authorized-password
```

権限不足ユーザーは、`manage_categories`だけを持ち、残り2権限を持たないユーザーを作ると、プラグイン独自の複合権限チェックを確認できます。

```sh
docker compose run --rm wp-cli user create phase1-restricted phase1-restricted@example.test --role=subscriber --user_pass=restricted-password
docker compose run --rm wp-cli user add-cap phase1-restricted manage_categories
docker compose run --rm wp-cli user list-caps phase1-restricted
```

確認内容:

1. `phase1-authorized`では「ツール」にTaxonomy Tidyが表示され、画面を開ける。
2. `phase1-restricted`ではメニューが表示されない。
3. `phase1-restricted`でTaxonomy TidyのURLを直接開いても画面へアクセスできない。

必要な3権限は`manage_categories`、`edit_others_posts`、`edit_published_posts`です。

## ローカル環境の初期化

次の操作は開発DB、Composer vendor、テスト用WordPressを含むプロジェクトのDocker volumeを削除します。

```sh
make reset
make setup
```

`make reset`は復元不能なローカルデータ削除を伴います。必要なデータを退避してから実行してください。コンテナとネットワークだけを停止し、データを残す場合は`make stop`を使用します。

## 配布用ZIPのvendor方針

配布用ZIPはホスト側でComposerを要求しない自己完結形式とし、実行に必要な`vendor/autoload.php`と本番用Composerファイルを含めます。Phase 8で`composer install --no-dev --optimize-autoloader`を使用して配布物を構築し、開発専用依存とテストファイルを除外します。Phase 1では配布用ZIP自体は生成しません。
