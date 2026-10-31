# ISUReal PHP 実装

`webapp/go` と同じ API を PHP で実装したものです。

- PHP 8.5 / [Slim](https://www.slimframework.com/) 4 / [PHP-DI](https://php-di.org/) / PDO（pdo_pgsql） / GD（JPEG の検証）
- php-fpm で動かし、nginx から FastCGI で受けます
- 依存パッケージは `composer.json` と `composer.lock` で固定しています

## 構成

| パス | 内容 |
|---|---|
| `public/index.php` | API の入口（Slim） |
| `public/notifications.php` | `GET /api/notifications`（Server-Sent Events）の入口 |
| `app/routes.php` | ルーティング |
| `app/dependencies.php` | DI コンテナの定義（設定と DB 接続） |
| `src/Handler/` | API ごとのハンドラ |
| `src/Repository/` | 複数のハンドラで使う DB の読み書き |
| `src/Model/` | ユーザー、投稿などのデータと応答の形 |
| `src/Http/` | エラー、リクエストの解釈、ミドルウェア |
| `src/Notification/NotificationStream.php` | 通知の配信 |
| `php-fpm.conf` | php-fpm の設定 |
| `tests/` | テスト |

`GET /api/notifications` は通知が届くまで接続を保持します。その間は php-fpm の worker を 1 つ使うので、
nginx はこのパスだけを別のプール（`notifications`、`127.0.0.1:9001`）に送ります。それ以外の API は
`www` プール（`127.0.0.1:9000`）が受けます。FrankenPHP で動かす場合の設定は `Caddyfile` にあります。
通知は同じ DB 接続で `LISTEN` し、`NOTIFY` または最長 5 秒の待機後に確認します。
接続時に見えている最新通知の作成時刻を開始位置にするため、その時点で未コミットだった通知も配信対象になります。

## 起動

```sh
make install   # 依存パッケージを入れ、DI・ルートキャッシュを事前生成する
make run       # php-fpm をフォアグラウンドで起動する
```

nginx の設定は、競技環境では `/etc/nginx/sites-available/isureal-php.conf`、開発環境では
`deploy/dev/nginx-php.conf` です。

## 環境変数

`webapp/go` と同じ `ISUREAL_REGION`、`ISUREAL_DB_HOST`、`ISUREAL_DB_PORT`、`ISUREAL_DB_USER`、
`ISUREAL_DB_PASSWORD`、`ISUREAL_DB_NAME`、`ISUREAL_LOG_LEVEL` を読みます（php-fpm の `clear_env = no` で worker に渡します）。

## テスト

```sh
make test   # 開発用のパッケージも入れてから PHPUnit を実行する
make lint   # PHPStan と PHP_CodeSniffer
```

DB を使うテスト（`tests/Integration`）は `ISUREAL_TEST_DATABASE=1` のときだけ実行します。`ISUREAL_DB_*` で
指定した DB のテーブルを作り直すので、使い捨ての DB を指定してください。同時リクエストと通知のテストは
`php -S` でサーバーを起動して確かめます。

```sh
ISUREAL_TEST_DATABASE=1 ISUREAL_DB_NAME=isureal_test make test
```

## リクエスト本文の一時書き込み

API と通知の入口は `Http/ServerRequestFactory` を使います。PHP が保持する `php://input` を直接読み、
Slim の再読込用 `php://temp` キャッシュへの重複書き込みを避けます。巻き戻しには PHP 自体の入力バッファを使い、
ヘッダー・Cookie・フォーム・アップロードの解釈は Slim の既存処理を利用します。

nginx の本文バッファと PHP 自体の入力バッファは、この変更の対象外です。

## DI・ルートキャッシュ

`make install` は依存パッケージの導入後に `make cache-warmup` を実行します。
Composer を直接実行するデプロイでは、その後に `php bin/warmup-cache.php` を実行してください。
PHP 8.5 以上が必要です。ウォームアップでは DB への接続や HTTP リクエストの実行は行いません。

デプロイ先の新しいリリースディレクトリで、全コードと依存パッケージを配置してからキャッシュを生成し、
生成が成功してからリリースを切り替えてください。コード・依存パッケージ・DI 定義・ルートを変更した場合は
必ず再生成が必要です。古いリリースのキャッシュを新しいリリースへコピーしないでください。

既定の保存先は `webapp/php/var/cache` です。`ISUREAL_PHP_CACHE_DIR` で変更する場合は、
ウォームアップと実行プロセスに同じパスを設定し、リリースごとに別のディレクトリを使ってください。
HTTP 実行ユーザーには読み取り権限だけを与え、キャッシュの生成はデプロイ処理で行います。

DI とルートは新しい世代に生成し、両方が完成してから参照先を原子的に切り替えます。
生成失敗時は前の世代を維持します。古い世代は実行中のリクエストが参照し得るため自動削除しません。
リリースの保持方針に従って、使用されなくなったリリースと一緒に回収してください。
キャッシュ未生成なら従来の初期化を使い、リクエスト中にファイルは生成しません。
設定値・認証情報・DB 接続・ユーザー情報はキャッシュせず、DI を差し替えるテストもキャッシュを使いません。

## フィード・投稿・画像

- フィードは閲覧権限、友達関係、ランキング、カーソル条件を SQL 内で処理し、最大 51 件を取得します。
- 投稿枠は `FOR SHARE` で保持し、別ユーザーの投稿を並行させます。同一ユーザーの投稿ロックと、投稿枠の終了処理との排他は維持します。
- 画像本体は地域内のファイルにキャッシュします。署名、有効期限、所有者・投稿等の削除状態はリクエストごとに確認します。
  DB が正となる構成は変わりません。キャッシュの破損・書き込み失敗時は DB から取得します。
  `ISUREAL_IMAGE_CACHE_DIR` で保存先を指定でき、既定は一時ディレクトリ下の `isureal-image-cache`、`off` で無効になります。
