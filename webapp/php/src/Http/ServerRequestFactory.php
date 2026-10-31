<?php

declare(strict_types=1);

namespace Isureal\Http;

use Slim\Psr7\Factory\ServerRequestFactory as SlimServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Request;

final class ServerRequestFactory
{
    public static function fromGlobals(): Request
    {
        // ヘッダー・Cookie・フォーム・アップロードの解釈は Slim に任せる。
        $request = SlimServerRequestFactory::createFromGlobals();

        // PHP 8.5 の php://input は巻き戻せる。Slim の再読込用キャッシュに
        // 本文をもう一度書き込まず、PHP が保持している入力をそのまま使う。
        return $request->withBody((new StreamFactory())->createStreamFromFile('php://input', 'r'));
    }
}
