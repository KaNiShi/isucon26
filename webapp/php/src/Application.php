<?php

declare(strict_types=1);

namespace Isureal;

use DI\ContainerBuilder;
use Isureal\Http\ActionLog;
use Isureal\Http\ErrorHandler;
use Isureal\Http\Middleware\ActionLogMiddleware;
use Isureal\Http\Middleware\IpBanMiddleware;
use Isureal\Model\Region;
use Slim\App;
use Slim\Factory\AppFactory;

final class Application
{
    /**
     * @param array<string, mixed> $definitions app/dependencies.php を上書きする定義（テスト用）
     * @return App<\DI\Container>
     */
    public static function create(array $definitions = [], ?string $cacheDirectory = null, bool $useCache = true): App
    {
        // テスト等の差し替え定義を、本番用のコンパイル済みコンテナへ混ぜない。
        $cache = $useCache && $definitions === [] ? ApplicationCache::load($cacheDirectory ?? ApplicationCache::directory()) : null;
        if ($cache === null) {
            $builder = new ContainerBuilder();
            $builder->addDefinitions(__DIR__ . '/../app/dependencies.php', $definitions);
            $container = $builder->build();
        } else {
            $container = $cache['container'];
        }

        $config = $container->get(Config::class);
        if (!Region::isValid($config->region)) {
            throw new \RuntimeException("invalid server region: {$config->region}");
        }

        $app = AppFactory::createFromContainer($container);
        (require __DIR__ . '/../app/routes.php')($app);
        if ($cache !== null) {
            $app->getRouteCollector()->setCacheFile($cache['routes']);
        }
        $app->addRoutingMiddleware();
        $app->add(IpBanMiddleware::class);
        $app->addErrorMiddleware(false, false, false)
            ->setDefaultErrorHandler(new ErrorHandler($app->getResponseFactory()));
        $app->add(new ActionLogMiddleware($app->getRouteResolver(), $container->get(ActionLog::class)));
        return $app;
    }
}
