<?php

declare(strict_types=1);

namespace Isureal\Http\Middleware;

use Isureal\Http\ApiError;
use Isureal\Http\ClientIp;
use Isureal\Repository\IpBanRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;

/**
 * ルートが見つからないとき（404、405）も IP BAN の 403 を先に返す。
 * ルートがあるときの IP BAN は、routes.php のグループごとに IpBanMiddleware か SessionMiddleware が確かめる
 */
final readonly class RoutingErrorIpBanMiddleware implements MiddlewareInterface
{
    public function __construct(private IpBanRepository $ipBans)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException | HttpMethodNotAllowedException $e) {
            if ($this->ipBans->isBanned(ClientIp::of($request))) {
                throw new ApiError(403, 'ip_banned');
            }
            throw $e;
        }
    }
}
