<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Isureal\Http\ServerRequestFactory;
use Slim\Psr7\Factory\ServerRequestFactory as SlimServerRequestFactory;

$request = ($_GET['implementation'] ?? '') === 'legacy'
    ? SlimServerRequestFactory::createFromGlobals()
    : ServerRequestFactory::fromGlobals();
$body = $request->getBody();
$first = (string) $body;
$second = (string) $body;
$uploaded = [];
foreach ($request->getUploadedFiles() as $name => $file) {
    $uploaded[$name] = [
        'name' => $file->getClientFilename(),
        'type' => $file->getClientMediaType(),
        'error' => $file->getError(),
        'sha256' => hash('sha256', (string) $file->getStream()),
    ];
}
$largeTemporaryFiles = [];
foreach (glob(sys_get_temp_dir() . '/*') ?: [] as $file) {
    $size = filesize($file);
    if ($size !== false && $size >= 2 * 1024 * 1024) {
        $largeTemporaryFiles[] = $size;
    }
}
header('Content-Type: application/json');
echo json_encode([
    'method' => $request->getMethod(),
    'contentType' => $request->getHeaderLine('Content-Type'),
    'header' => $request->getHeaderLine('X-Test-Header'),
    'cookies' => $request->getCookieParams(),
    'parsedBody' => $request->getParsedBody(),
    'uploaded' => $uploaded,
    'bodyBytes' => strlen($first),
    'firstHash' => hash('sha256', $first),
    'secondHash' => hash('sha256', $second),
    'seekable' => $body->isSeekable(),
    'largeTemporaryFiles' => $largeTemporaryFiles,
], JSON_THROW_ON_ERROR);
