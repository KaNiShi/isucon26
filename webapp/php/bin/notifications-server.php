<?php

declare(strict_types=1);

use Isureal\Config;
use Isureal\Http\ActionLog;
use Isureal\Notification\NotificationEventServer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnv();
(new NotificationEventServer($config, ActionLog::toStdout($config)))->run();
