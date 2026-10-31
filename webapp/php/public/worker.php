<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// DI コンテナ・ルート・ミドルウェアは Worker の起動時に 1 回だけ作る
$app = Isureal\Application::create();
$container = $app->getContainer();

$handler = static function () use ($app, $container): void {
    try {
        $app->run(Isureal\Http\ServerRequestFactory::fromGlobals());
    } finally {
        $container->get(Isureal\Database::class)->reset();
    }
};

// メモリが増え続けるのを避けるため、一定数さばいたら Worker を作り直す
$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);
for ($handled = 0; $maxRequests === 0 || $handled < $maxRequests; $handled++) {
    if (!frankenphp_handle_request($handler)) {
        break;
    }
    gc_collect_cycles();
}
