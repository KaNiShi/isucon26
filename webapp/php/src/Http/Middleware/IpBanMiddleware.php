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

final readonly class IpBanMiddleware implements MiddlewareInterface
{
    public const string SESSION_ATTRIBUTE = 'prefetched_session';

    public function __construct(private IpBanRepository $ipBans)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = ClientIp::of($request);
        $header = $request->getHeader('Authorization')[0] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            [$banned, $session] = $this->ipBans->isBannedWithSession($ip, substr($header, strlen('Bearer ')));
            if ($banned) {
                throw new ApiError(403, 'ip_banned');
            }
            if ($session !== null) {
                $request = $request->withAttribute(self::SESSION_ATTRIBUTE, $session);
            }
            return $handler->handle($request);
        }
        if ($this->ipBans->isBanned($ip)) {
            throw new ApiError(403, 'ip_banned');
        }
        return $handler->handle($request);
    }
}
