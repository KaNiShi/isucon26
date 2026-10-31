<?php

declare(strict_types=1);

namespace Isureal\Tests\Integration;

use CURLFile;
use PHPUnit\Framework\TestCase;

final class ServerRequestFactoryTest extends TestCase
{
    /** @var resource|null */
    private static mixed $server = null;
    /** @var resource|null */
    private static mixed $log = null;
    private static string $directory;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        self::$port = (int) substr($address, (int) strrpos($address, ':') + 1);
        fclose($socket);
        self::$directory = sys_get_temp_dir() . '/isureal-request-test-' . bin2hex(random_bytes(8));
        mkdir(self::$directory);
        self::$log = tmpfile();
        self::assertIsResource(self::$log);
        $server = proc_open(
            [PHP_BINARY, '-d', 'sys_temp_dir=' . self::$directory, '-S', '127.0.0.1:' . self::$port, '-t', __DIR__ . '/fixtures'],
            [0 => ['file', '/dev/null', 'r'], 1 => self::$log, 2 => self::$log],
            $pipes,
        );
        self::assertIsResource($server);
        self::$server = $server;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $error, $message, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(20_000);
        }
        self::fail('PHP test server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (is_resource(self::$log)) {
            fclose(self::$log);
        }
        if (isset(self::$directory)) {
            foreach (glob(self::$directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir(self::$directory);
        }
    }

    public function testLargeBodyCanBeReadTwiceWithoutTheExtraDiskCopy(): void
    {
        $data = str_repeat('x', 31 * 1024 * 1024);
        $legacy = self::request('legacy', 'PUT', $data, ['Content-Type: image/jpeg']);
        $current = self::request('current', 'PUT', $data, ['Content-Type: image/jpeg']);
        self::assertSame(strlen($data), $current['bodyBytes']);
        self::assertSame(hash('sha256', $data), $current['firstHash']);
        self::assertSame($current['firstHash'], $current['secondHash']);
        self::assertTrue($current['seekable']);
        self::assertSame($legacy['firstHash'], $current['firstHash']);
        self::assertSame($legacy['secondHash'], $current['secondHash']);
        self::assertIsArray($legacy['largeTemporaryFiles']);
        self::assertIsArray($current['largeTemporaryFiles']);
        self::assertGreaterThan(count($current['largeTemporaryFiles']), count($legacy['largeTemporaryFiles']));
    }

    public function testPreservesHeadersCookiesAndUrlEncodedForm(): void
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'X-Test-Header: example', 'Cookie: theme=dark'];
        $current = self::request('current', 'POST', 'caption=hello&tags%5B%5D=one&tags%5B%5D=two', $headers);
        self::assertSame('POST', $current['method']);
        self::assertSame('example', $current['header']);
        self::assertSame(['theme' => 'dark'], $current['cookies']);
        self::assertSame(['caption' => 'hello', 'tags' => ['one', 'two']], $current['parsedBody']);
        self::assertSame($current['firstHash'], $current['secondHash']);
    }

    public function testPreservesMultipartFieldsAndUploadedFile(): void
    {
        $path = self::$directory . '/client-upload.txt';
        file_put_contents($path, 'upload fixture');
        try {
            $current = self::request('current', 'POST', [
                'caption' => 'example',
                'image' => new CURLFile($path, 'text/plain', 'example.txt'),
            ]);
            self::assertSame(['caption' => 'example'], $current['parsedBody']);
            self::assertSame(['image' => [
                'name' => 'example.txt',
                'type' => 'text/plain',
                'error' => UPLOAD_ERR_OK,
                'sha256' => hash('sha256', 'upload fixture'),
            ]], $current['uploaded']);
        } finally {
            unlink($path);
        }
    }

    public function testPreservesChunkedBodyAndEmptyBody(): void
    {
        $data = str_repeat('chunked body ', 1024);
        $current = self::request('current', 'POST', $data, ['Content-Type: application/json', 'Transfer-Encoding: chunked']);
        self::assertSame(strlen($data), $current['bodyBytes']);
        self::assertSame(hash('sha256', $data), $current['firstHash']);
        self::assertSame($current['firstHash'], $current['secondHash']);
        $empty = self::request('current', 'POST', '', ['Content-Type: application/json']);
        self::assertSame(0, $empty['bodyBytes']);
        self::assertSame(hash('sha256', ''), $empty['secondHash']);
    }

    /**
     * @param non-empty-string $method
     * @param string|array<string, string|CURLFile> $data
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private static function request(string $implementation, string $method, string|array $data, array $headers = []): array
    {
        $curl = curl_init('http://127.0.0.1:' . self::$port . '/request.php?implementation=' . $implementation);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROXY => '',
        ]);
        $response = curl_exec($curl);
        self::assertIsString($response, curl_error($curl));
        self::assertSame(200, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response);
        /** @var array<string, mixed> $result */
        $result = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        return $result;
    }
}
