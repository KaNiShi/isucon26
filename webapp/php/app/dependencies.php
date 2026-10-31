<?php

declare(strict_types=1);

use Isureal\Config;
use Isureal\Database;
use Isureal\Handler;
use Isureal\Http\ActionLog;
use Isureal\Http\Middleware;
use Isureal\ImageCache;
use Isureal\Repository;
use Psr\Container\ContainerInterface;

use function DI\autowire;

// コンパイル対象を明示する。ここにないクラスは従来どおり自動解決する。
return [
    Config::class => static fn (): Config => Config::fromEnv(),
    Database::class => static fn (ContainerInterface $c): Database => Database::connect($c->get(Config::class)),
    ImageCache::class => static fn (ContainerInterface $c): ImageCache => ImageCache::fromConfig($c->get(Config::class)),
    ActionLog::class => static fn (ContainerInterface $c): ActionLog => ActionLog::toStdout($c->get(Config::class)),
    Handler\AdminHandler::class => autowire(),
    Handler\AuthHandler::class => autowire(),
    Handler\CommentHandler::class => autowire(),
    Handler\ExportHandler::class => autowire(),
    Handler\FeedHandler::class => autowire(),
    Handler\FriendRequestHandler::class => autowire(),
    Handler\ImageHandler::class => autowire(),
    Handler\InitializeHandler::class => autowire(),
    Handler\MeHandler::class => autowire(),
    Handler\MemoryHandler::class => autowire(),
    Handler\PostHandler::class => autowire(),
    Handler\PostingWindowHandler::class => autowire(),
    Handler\RealMojiHandler::class => autowire(),
    Handler\RelationshipHandler::class => autowire(),
    Handler\UserHandler::class => autowire(),
    Middleware\AdminSessionMiddleware::class => autowire(),
    Middleware\IpBanMiddleware::class => autowire(),
    Middleware\RegionMiddleware::class => autowire(),
    Middleware\SessionMiddleware::class => autowire(),
    Repository\AdminSessionRepository::class => autowire(),
    Repository\CommentRepository::class => autowire(),
    Repository\IpBanRepository::class => autowire(),
    Repository\PostRepository::class => autowire(),
    Repository\PostingWindowRepository::class => autowire(),
    Repository\RealMojiRepository::class => autowire(),
    Repository\RelationshipRepository::class => autowire(),
    Repository\SessionRepository::class => autowire(),
    Repository\UserRepository::class => autowire(),
];
