<?php

// tests/Unit/Service/Import/FileChecksumCalculatorTest.php
declare(strict_types=1);

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\FileChecksumCalculator;
use PHPUnit\Framework\TestCase;

final class FileChecksumCalculatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/chk_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testCalculatesSha256ForFile(): void
    {
        $path = $this->dir.'/a.txt';
        file_put_contents($path, 'hello');

        $calc = new FileChecksumCalculator(); // deine Klasse
        $hash = $calc->forPath($path);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame(hash_file('sha256', $path), $hash);
    }

    public function testDifferentFilesProduceDifferentHashes(): void
    {
        $a = $this->dir.'/a.txt';
        $b = $this->dir.'/b.txt';
        file_put_contents($a, 'hello');
        file_put_contents($b, 'hello!'); // minimaler Unterschied

        $calc = new FileChecksumCalculator();
        self::assertNotSame($calc->forPath($a), $calc->forPath($b));
    }

    public function testThrowsWhenFileMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FileChecksumCalculator())->forPath($this->dir.'/missing.txt');
    }
}
