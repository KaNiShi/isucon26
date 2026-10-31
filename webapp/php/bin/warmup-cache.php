<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Isureal\ApplicationCache;

ApplicationCache::warmup(ApplicationCache::directory());
fwrite(STDOUT, "Application cache warmed up.\n");
