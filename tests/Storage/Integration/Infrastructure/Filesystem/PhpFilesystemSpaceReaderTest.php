<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Filesystem;

use App\Storage\Infrastructure\Filesystem\PhpFilesystemSpaceReader;
use PHPUnit\Framework\TestCase;

final class PhpFilesystemSpaceReaderTest extends TestCase
{
    public function testReadsTheFilesystemOfAnExistingPath(): void
    {
        $space = new PhpFilesystemSpaceReader()->read(sys_get_temp_dir());

        self::assertNotNull($space);
        self::assertGreaterThan(0, $space->totalBytes);
        self::assertLessThanOrEqual($space->totalBytes, $space->freeBytes);
    }

    public function testAMissingPathHasNoSpace(): void
    {
        self::assertNull(new PhpFilesystemSpaceReader()->read(sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(4))));
    }
}
