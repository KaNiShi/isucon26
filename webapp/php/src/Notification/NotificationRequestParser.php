<?php

declare(strict_types=1);

namespace Isureal\Notification;

use Isureal\Http\ApiError;

final class NotificationRequestParser
{
    public const int MAX_HEADER_BYTES = 16_384;
    public const int MAX_HEADER_COUNT = 100;

    /**
     * @return array{method: string, target: string, version: string, has_body: bool, headers: list<array{string, string}>}|null
     */
    public static function parse(string $buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > self::MAX_HEADER_BYTES) {
                throw new ApiError(431, 'request_header_too_large');
            }
            return null;
        }
        if ($end + 4 > self::MAX_HEADER_BYTES) {
            throw new ApiError(431, 'request_header_too_large');
        }

        $lines = explode("\r\n", substr($buffer, 0, $end));
        $requestLine = array_shift($lines);
        if (preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+) ([^\s]+) (HTTP\/1\.[01])$/D', $requestLine, $match) !== 1) {
            throw ApiError::badRequest();
        }

        if (count($lines) > self::MAX_HEADER_COUNT) {
            throw new ApiError(431, 'request_header_too_large');
        }

        $headers = [];
        $contentLength = null;
        $hasTransferEncoding = false;
        foreach ($lines as $line) {
            if (preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*(.*?)[ \t]*$/D', $line, $headerMatch) !== 1) {
                throw ApiError::badRequest();
            }
            $name = $headerMatch[1];
            $value = $headerMatch[2];
            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                throw ApiError::badRequest();
            }
            if (strcasecmp($name, 'Content-Length') === 0) {
                if (!ctype_digit($value)) {
                    throw ApiError::badRequest();
                }
                if ($contentLength !== null && (string) $contentLength !== $value) {
                    throw ApiError::badRequest();
                }
                $contentLength = (int) $value;
            }
            if (strcasecmp($name, 'Transfer-Encoding') === 0) {
                $hasTransferEncoding = true;
            }
            $headers[] = [$name, $value];
        }

        return [
            'method' => $match[1],
            'target' => $match[2],
            'version' => $match[3],
            'has_body' => $hasTransferEncoding || ($contentLength !== null && $contentLength > 0),
            'headers' => $headers,
        ];
    }
}
