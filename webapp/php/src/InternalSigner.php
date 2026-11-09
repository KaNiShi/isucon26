<?php

declare(strict_types=1);

namespace Isureal;

final class InternalSigner
{
    private const int MAX_ITEMS = 100000;

    public static function handle(): void
    {
        $token = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals(ImageSignature::INTERNAL_TOKEN, $token)) {
            http_response_code(403);
            return;
        }
        $items = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($items) || count($items) > self::MAX_ITEMS) {
            http_response_code(400);
            return;
        }
        $valid = [];
        foreach ($items as $item) {
            if (!is_array($item) || count($item) !== 3 || !is_string($item[0]) || !is_string($item[1]) || !is_int($item[2])
                || !preg_match('/\\A[0-9a-f]{64}\\z/', $item[0])) {
                http_response_code(400);
                return;
            }
            $valid[] = [$item[0], $item[1], $item[2]];
        }
        header('Content-Type: application/json');
        echo json_encode(array_map('base64_encode', ImageSignature::signBatch($valid)), JSON_THROW_ON_ERROR);
    }
}
