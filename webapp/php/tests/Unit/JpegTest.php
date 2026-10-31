<?php

declare(strict_types=1);

namespace Isureal\Tests\Unit;

use Isureal\Jpeg;
use PHPUnit\Framework\TestCase;

final class JpegTest extends TestCase
{
    public function testPreservesExistingDecoderResults(): void
    {
        $image = imagecreatetruecolor(16, 12);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imageinterlace($image, true);
        ob_start();
        imagejpeg($image);
        $progressive = (string) ob_get_clean();
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        ob_start();
        imagegif($image);
        $gif = (string) ob_get_clean();

        $cases = [
            'baseline' => $jpeg,
            'progressive' => $progressive,
            'png' => $png,
            'gif' => $gif,
            'empty' => '',
            'text' => 'not a JPEG',
            'SOI only' => "\xff\xd8",
            'fake JPEG' => "\xff\xd8" . str_repeat('x', 8192),
            'leading garbage' => 'x' . $jpeg,
            'trailing data' => $jpeg . str_repeat('x', 8192),
            'missing EOI' => substr($jpeg, 0, -2),
        ];
        foreach ($cases as $name => $data) {
            self::assertSame(self::originalSize($data), Jpeg::size($data), $name);
        }
        for ($offset = 0; $offset < strlen($jpeg); $offset += 8) {
            $truncated = substr($jpeg, 0, $offset);
            self::assertSame(self::originalSize($truncated), Jpeg::size($truncated), "truncated at {$offset}");
            $corrupted = substr_replace($jpeg, "\x00", $offset, 1);
            self::assertSame(self::originalSize($corrupted), Jpeg::size($corrupted), "corrupted at {$offset}");
        }
    }

    public function testRejectsLargeNonJpegWithoutAccessToTemporaryFiles(): void
    {
        // open_basedir の外には一時ファイルを作れない環境でも拒否できる。
        $root = dirname(__DIR__, 2);
        $program = 'require ' . var_export($root . '/src/Jpeg.php', true) . ';'
            . 'echo json_encode(Isureal\\Jpeg::size(str_repeat("x", 31 * 1024 * 1024)));';
        $process = proc_open(
            [PHP_BINARY, '-d', "open_basedir={$root}", '-d', 'sys_temp_dir=/isureal-no-temporary-directory', '-r', $program],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors);
        self::assertSame('null', $output);
        self::assertSame('', $errors);
    }

    /** @return array{int, int}|null */
    private static function originalSize(string $data): ?array
    {
        ini_set('gd.jpeg_ignore_warning', '0');
        $path = tempnam(sys_get_temp_dir(), 'jpeg-reference-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $data);
            $image = @imagecreatefromjpeg($path);
            return $image === false ? null : [imagesx($image), imagesy($image)];
        } finally {
            unlink($path);
        }
    }
}
