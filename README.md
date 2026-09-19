# Taxonomy Tidy

Taxonomy Tidy は、WordPress 管理画面から標準カテゴリーと標準タグを安全に整理するためのプラグインです。変更前のプレビュー、有界バッチ、操作履歴、対応可能な Undo を通して、意図しない投稿変更を防ぎます。

## MVP でできること

- カテゴリーとタグの検索、並べ替え、未使用絞り込み、数値ページネーション
- 名称変更と、明示した場合だけの slug 変更
- 同一 taxonomy 内の既存タームへの統合
- WordPress 全体で完全に未使用なタームの削除
- カテゴリーとタグの計画をまとめたプレビューと一括実行
- 中断可能な有界バッチ、結果・警告・一部失敗の記録
- 操作履歴からの Undo プレビューと安全な Undo

relationship の変更対象は、標準投稿タイプ `post` の公開済み投稿だけです。固定ページ、カスタム投稿タイプ、下書き、非公開、予約、承認待ち、ゴミ箱、自動下書きの relationship は変更しません。

## MVP 対象外

AI 分類、本文解析、カスタム投稿タイプ、カスタム taxonomy、CSV 入出力、定期実行、Redo、複雑な計画編集、類似語の自動統合、SEO リダイレクト、マルチサイト全体の一括処理は対象外です。

## 対応環境

- WordPress 6.6 以上
- PHP 8.2 以上
- MySQL 8.0 以上、または MariaDB 10.11 以上

## ローカル開発

Docker Desktop（または Docker Engine と Compose v2）、GNU Make 互換の `make`、Git が必要です。

```sh
cp .env.example .env
make setup
```

既定の管理画面は `http://localhost:8080/wp-admin/`、Taxonomy Tidy は「ツール > Taxonomy Tidy」にあります。詳しい起動・シード・権限確認手順は [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) を参照してください。

## テスト

```sh
make check
```

このコマンドは PHPCS、JavaScript lint、PHPUnit と WordPress 統合テストを実行します。個別には `make phpcs`、`make lint-js`、`make test` を利用できます。

## プラグインの有効化

ローカル Docker 環境では次を実行します。

```sh
make activate
docker compose run --rm wp-cli plugin status taxonomy-tidy
```

## 安全上の注意

- 本番利用前に、WordPress のデータベースとアップロードファイルを必ずバックアップしてください。
- 実行前にプレビューの対象、変更内容、保持・削除予定、警告を確認してください。
- 公開済み標準投稿以外の relationship は対象外です。ただし、統合元や削除対象の安全判定では WordPress 全体の relationship を確認します。
- 対象外オブジェクトで使用中の統合元、子カテゴリーを持つ統合元、デフォルトカテゴリーは安全側に保持します。
- プレビュー後に状態が変わった操作は実行せず、新しい管理者変更を Undo で上書きしません。

配布 ZIP の作成と新規環境へのインストール確認は Phase 8 の対象であり、現時点の開発ツリーはリリース成果物ではありません。
