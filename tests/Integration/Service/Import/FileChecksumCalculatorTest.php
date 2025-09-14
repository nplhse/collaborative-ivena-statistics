<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Service\Import\FileChecksumCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class FileChecksumCalculatorTest extends TestCase
{
    private string $projectDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        // Arrange
        $this->projectDir = sys_get_temp_dir().'/proj_'.bin2hex(random_bytes(4));
        $this->fs = new Filesystem();
        $this->fs->mkdir($this->projectDir, 0775);
    }

    protected function tearDown(): void
    {
        // Arrange
        if (is_dir($this->projectDir)) {
            $this->fs->remove($this->projectDir);
        }
        parent::tearDown();
    }

    public function testComputesHashForRelativePath(): void
    {
        // Arrange
        $abs = Path::join($this->projectDir, 'var', 'imports', 'x.txt');
        $this->fs->mkdir(\dirname($abs));
        file_put_contents($abs, 'hello');

        $rel = Path::makeRelative($abs, $this->projectDir);
        $rel = str_replace('\\', '/', $rel);

        $calc = new FileChecksumCalculator($this->projectDir, 'sha256');

        // Act
        $hash = $calc->forPath($rel);

        // Assert
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testComputesHashForAbsolutePath(): void
    {
        // Arrange
        $abs = Path::join($this->projectDir, 'file.csv');
        file_put_contents($abs, 'data');

        $calc = new FileChecksumCalculator($this->projectDir, 'sha256');

        // Act
        $hash = $calc->forPath($abs);

        // Assert
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testThrowsOnMissingFile(): void
    {
        // Arrange
        $calc = new FileChecksumCalculator($this->projectDir, 'sha256');
        $rel = 'var/imports/missing.csv';

        // Act + Assert
        $this->expectException(\RuntimeException::class);
        $calc->forPath($rel);
    }
}
