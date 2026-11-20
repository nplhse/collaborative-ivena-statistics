<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\MessageHandler;

use App\Import\Application\Message\PurgePendingImportUploadsMessage;
use App\Import\Application\MessageHandler\PurgePendingImportUploadsMessageHandler;
use App\Import\Application\Service\PendingImportUploadStore;
use App\Shared\Infrastructure\Monitoring\JobRun\CurrentJobRun;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class PurgePendingImportUploadsMessageHandlerTest extends TestCase
{
    public function testTheJobRunRecordsHowManyFilesWereDeleted(): void
    {
        $baseDir = sys_get_temp_dir().'/pending-upload-purge-'.bin2hex(random_bytes(4));
        $clock = new MockClock('2026-11-20 12:00:00');
        $filesystem = new Filesystem();
        // One abandoned upload of two files, 25 hours old.
        foreach (['upload', 'json'] as $extension) {
            $path = $baseDir.'/pending/3/'.str_repeat('a', 32).'.'.$extension;
            $filesystem->dumpFile($path, 'x');
            touch($path, $clock->now()->getTimestamp() - 25 * 3600);
        }

        $jobRun = new CurrentJobRun();
        $jobRun->begin();
        new PurgePendingImportUploadsMessageHandler(new PendingImportUploadStore($baseDir, $filesystem, $clock), $jobRun)(new PurgePendingImportUploadsMessage());

        self::assertSame(['deleted_files' => 2], $jobRun->metrics());
        $filesystem->remove($baseDir);
    }
}
