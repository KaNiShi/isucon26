<?php

declare(strict_types=1);

// GET /api/notifications の FrankenPHP worker（Caddyfile の worker ... match で振り分ける）
// 1 リクエストを通知が届くまで保持するので、他の API とは別のスレッドを専用に割り当てる。
// php-fpm では notifications.php を使う
require __DIR__ . '/../vendor/autoload.php';

use Isureal\Config;
use Isureal\Http\ActionLog;
use Isureal\Http\ServerRequestFactory;
use Isureal\Notification\NotificationStream;

$config = Config::fromEnv();

$handler = static function () use ($config): void {
    new NotificationStream($config, ActionLog::toStdout($config))->handle(ServerRequestFactory::fromGlobals());
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
