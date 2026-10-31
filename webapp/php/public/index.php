<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$request = Isureal\Http\ServerRequestFactory::fromGlobals();
// 古い・不完全なキャッシュがあっても、initialize は現在のコードで再生成できる。
$initialize = $request->getMethod() === 'POST' && $request->getUri()->getPath() === '/api/initialize';
Isureal\Application::create(useCache: !$initialize)->run($request);
