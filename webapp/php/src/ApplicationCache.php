<?php

declare(strict_types=1);

namespace Isureal;

use DI\Container;
use DI\ContainerBuilder;
use RuntimeException;
use Slim\Factory\AppFactory;

final class ApplicationCache
{
    public static function directory(): string
    {
        return getenv('ISUREAL_PHP_CACHE_DIR') ?: __DIR__ . '/../var/cache';
    }

    /**
     * 生成済みの同じ世代の DI とルートだけを読む。通常リクエストでは生成しない。
     *
     * @return array{container: Container, routes: string}|null
     */
    public static function load(string $directory): ?array
    {
        if (!is_file($directory . '/current')) {
            return null;
        }
        $generation = file_get_contents($directory . '/current');
        if ($generation === false || preg_match('/\A[a-f0-9]{16}\z/', $generation) !== 1) {
            throw new RuntimeException('invalid application cache generation');
        }
        $class = 'IsurealCompiledContainer_' . $generation;
        $path = $directory . '/' . $generation;
        if (!is_file($path . '/' . $class . '.php') || !is_file($path . '/routes.php')) {
            throw new RuntimeException('incomplete application cache; run cache warmup before deployment');
        }
        require_once $path . '/' . $class . '.php';
        /** @var Container $container */
        $container = new $class();
        return ['container' => $container, 'routes' => $path . '/routes.php'];
    }

    /**
     * initialize 成功時、またはデプロイ前に実行する。この処理自体は DB に接続しない。
     * 設定値・利用者データもキャッシュしない。
     * 完成してから current を切り替えるので、生成失敗時も前の世代を使える。
     */
    public static function warmup(string $directory): void
    {
        $generation = bin2hex(random_bytes(8));
        $path = $directory . '/' . $generation;
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('failed to create application cache directory');
        }
        $builder = new ContainerBuilder();
        $builder->addDefinitions(__DIR__ . '/../app/dependencies.php');
        $builder->enableCompilation($path, 'IsurealCompiledContainer_' . $generation);
        $container = $builder->build();

        $app = AppFactory::createFromContainer($container);
        (require __DIR__ . '/../app/routes.php')($app);
        $app->getRouteCollector()->setCacheFile($path . '/routes.php');
        $app->getRouteResolver()->computeRoutingResults('/__cache_warmup__', 'GET');

        $pointer = $path . '/current';
        if (file_put_contents($pointer, $generation) === false || !rename($pointer, $directory . '/current')) {
            throw new RuntimeException('failed to publish application cache');
        }
    }
}
