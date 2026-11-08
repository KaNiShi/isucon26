<?php

declare(strict_types=1);

namespace Isureal\Http\Middleware;

use Isureal\Http\ActionLog;
use Isureal\Http\ActionLogEntry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteResolverInterface;
use Slim\Routing\RoutingResults;

final readonly class ActionLogMiddleware implements MiddlewareInterface
{
    public function __construct(private RouteResolverInterface $routes, private ActionLog $log)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $entry = new ActionLogEntry();
        $startedAt = microtime(true);
        $response = $handler->handle($request->withAttribute(ActionLogEntry::ATTRIBUTE, $entry));
        $this->log->write($request, $this->route($request), $response->getStatusCode(), $entry);
        $handlerMs = (microtime(true) - $startedAt) * 1000;
        $bootMs = ($startedAt - (float) ($request->getServerParams()['REQUEST_TIME_FLOAT'] ?? $startedAt)) * 1000;
        if ($handlerMs + $bootMs > 500) {
            error_log('slow ' . json_encode([
                'method' => $request->getMethod(),
                'path' => preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27}/', ':id', $request->getUri()->getPath()),
                'status' => $response->getStatusCode(),
                'boot_ms' => (int) $bootMs,
                'handler_ms' => (int) $handlerMs,
                'bytes' => (int) ($request->getHeader('Content-Length')[0] ?? 0),
            ]));
        }
        return $response;
    }

    private function route(ServerRequestInterface $request): ?string
    {
        $results = $this->routes->computeRoutingResults(rawurldecode($request->getUri()->getPath()), $request->getMethod());
        $identifier = $results->getRouteIdentifier();
        if ($results->getRouteStatus() !== RoutingResults::FOUND || $identifier === null) {
            return null;
        }
        return preg_replace('/\{(\w+)\}/', ':$1', $this->routes->resolveRoute($identifier)->getPattern());
    }
}
