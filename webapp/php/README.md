# ISUReal PHP 実装

`webapp/go` と同じ API を PHP で実装したものです。

- PHP 8.5 / [Slim](https://www.slimframework.com/) 4 / [PHP-DI](https://php-di.org/) / PDO（pdo_pgsql） / GD（JPEG の検証）
- 通常の API は php-fpm で動かし、nginx から FastCGI で受けます
- SSE 通知は専用の PHP CLI イベントループが HTTP と非同期 socket で受けます
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
| `src/Notification/NotificationStream.php` | php-fpm 向け通知互換経路 |
| `src/Notification/NotificationEventServer.php` | SSE 通知の専用イベントループ |
| `src/Notification/NotificationBatch.php` | 接続中通知をまとめて確認するクエリ |
| `bin/notifications-server.php` | 専用通知サーバーの入口 |
| `php-fpm.conf` | php-fpm の設定 |

`GET /api/notifications` は届いた通知を1件送って接続を閉じます。通知の正本は `notifications` テーブルで、
管理 API は投稿期間開始を同じトランザクションから `posting_window_opened` チャンネルへ通知します。専用サーバーは
共有 DB 接続で `LISTEN` し、通知を受けると全接続を1回の問い合わせで確認します。通知を発行しない既存 writer、
サーバー再接続、退会・リージョン変更の検出には500msごとの同じバッチ問い合わせを使います。

専用サーバーは非同期 socket を単一イベントループで処理し、既定で `127.0.0.1:9002` を listen します。接続数は
`ISUREAL_NOTIFICATION_MAX_CONNECTIONS` で調整でき、既定値・上限は256です。
HTTPヘッダー、要求タイムアウト、出力バッファ、遅いクライアントにも上限を設けています。

移行中の互換性として `public/notifications.php` と php-fpm の `notifications` プール（`127.0.0.1:9001`）も残ります。
新しい nginx 設定では通知パスを専用サーバーへ送ります。API本体は引き続き `www` プール（`127.0.0.1:9000`）です。

## 起動

```sh
make install   # 依存パッケージを vendor/ に入れる
make run       # php-fpm をフォアグラウンドで起動する
make run-notifications  # 専用通知サーバーをフォアグラウンドで起動する
```

`notification-proxy.conf.example` は、既存の API 用 nginx server block に追加する開発向け location 例です。
専用サーバーと php-fpm の両方を起動し、この location が `/api/notifications` を `127.0.0.1:9002` へ転送するようにします。

## 環境変数

`webapp/go` と同じ `ISUREAL_REGION`、`ISUREAL_DB_HOST`、`ISUREAL_DB_PORT`、`ISUREAL_DB_USER`、
`ISUREAL_DB_PASSWORD`、`ISUREAL_DB_NAME`、`ISUREAL_LOG_LEVEL` を読みます（php-fpm の `clear_env = no` で worker に渡します）。
専用通知サーバーでは `ISUREAL_NOTIFICATION_HOST`（既定 `127.0.0.1`）、`ISUREAL_NOTIFICATION_PORT`（既定 `9002`）、
`ISUREAL_NOTIFICATION_MAX_CONNECTIONS`（既定 `256`、最大 `256`）も指定できます。

## テスト

```sh
make test   # 開発用のパッケージも入れてから PHPUnit を実行する
make lint   # PHPStan と PHP_CodeSniffer
```

## リクエストの読み込み

通常 API と php-fpm の互換通知入口は `Http/ServerRequestFactory` を使います。PHP が保持する `php://input` を直接読み、
Slim の再読込用 `php://temp` キャッシュへの重複書き込みを避けます。専用通知サーバーは HTTP ヘッダーを上限付きで
読み取り、既存のセッションミドルウェアへリクエストを渡します。

nginx の本文バッファと PHP 自体の入力バッファは、この変更の対象外です。
