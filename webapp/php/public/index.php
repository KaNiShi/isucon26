<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && strtok($_SERVER['REQUEST_URI'] ?? '', '?') === Isureal\ImageSignature::INTERNAL_PATH) {
    Isureal\InternalSigner::handle();
    exit;
}

Isureal\Application::create()->run();
