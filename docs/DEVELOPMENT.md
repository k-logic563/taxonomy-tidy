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
