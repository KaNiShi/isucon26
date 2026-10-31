<?php

declare(strict_types=1);

namespace Isureal\Http\Middleware;

use Isureal\Http\ActionLogEntry;
use Isureal\Http\ApiError;
use Isureal\Http\ClientIp;
use Isureal\Model\LoginSession;
use Isureal\Repository\SessionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * ログインセッションを確かめ、リクエストの属性 session に入れる
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'session';

    public function __construct(private SessionRepository $sessions)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $this->authenticate($request)));
    }

    /**
     * IP BAN もセッションと同じ問い合わせで確かめる（このミドルウェアを使うルートには IpBanMiddleware を付けない）。
     * BAN の 403 をトークンの誤りの 401 より先に返す
     */
    public function authenticate(ServerRequestInterface $request): LoginSession
    {
        try {
            $token = BearerToken::from($request, 'invalid_session');
            $tokenError = null;
        } catch (ApiError $e) {
            [$token, $tokenError] = [null, $e];
        }
        [$banned, $session] = $this->sessions->findByTokenCheckingIpBan($token, ClientIp::of($request));
        if ($banned) {
            throw new ApiError(403, 'ip_banned');
        }
        if ($tokenError !== null) {
            throw $tokenError;
        }
        if ($session === null) {
            throw new ApiError(401, 'invalid_session');
        }
        ActionLogEntry::of($request)->userId = $session->userId;
        return $session;
    }

    public static function session(ServerRequestInterface $request): LoginSession
    {
        return $request->getAttribute(self::ATTRIBUTE) ?? throw new ApiError(401, 'invalid_session');
    }
}
