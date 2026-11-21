<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Media;

use App\Content\Application\Contract\MediaIntegrityCheckerInterface;
use App\Content\Application\Media\MediaIntegrityReport;
use App\Content\Application\Media\MediaMaintenanceException;
use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Health\MediaHealthCheck;
use App\Content\Infrastructure\Media\MediaIntegrityChecker;
use App\Content\Infrastructure\Media\MediaStorage;
use App\Content\Infrastructure\Media\MediaStorageMigrator;
use App\Content\Infrastructure\Repository\MediaIntegrityCheckRepository;
use App\Shared\Application\Health\HealthCheckStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The media integrity check (ADR 039). "Now" is 2026-11-21 10:00; the grace period is 24 hours, so a file last
 * changed three days ago is old enough to be deleted and one changed an hour ago is not.
 */
#[ResetDatabase]
final class MediaIntegrityCheckerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string NOW = '2026-11-21 10:00:00';
    private const string UNKNOWN_UUID = '0193a8f2-7c1e-7000-8000-00000000abcd';

    private int $now;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        new Filesystem()->remove($this->storage()->root());
        self::mockTime(self::NOW);
        $this->now = new \DateTimeImmutable(self::NOW)->getTimestamp();
    }

    public function testReportsMissingAndDamagedFilesAndDeletesOldOrphans(): void
    {
        $intact = MediaFactory::createOne();
        $missing = MediaFactory::createOne();
        $sameSizeOtherContent = MediaFactory::createOne();
        $truncated = MediaFactory::createOne();
        unlink($this->path($missing));
        file_put_contents($this->path($sameSizeOtherContent), str_repeat('x', (int) $sameSizeOtherContent->getSize()));
        file_put_contents($this->path($truncated), 'short');

        $oldOrphan = $this->file('originals/2026/10/0193a8f2-7c1e-7000-8000-000000000001.png', 3 * 86400);
        $freshOrphan = $this->file('originals/2026/11/0193a8f2-7c1e-7000-8000-000000000002.png', 3600);
        $this->file('.tmp/0193a8f2-7c1e-7000-8000-000000000003.part', 3 * 86400);
        $ownVariant = $this->file($this->variantOf($intact).'/thumb-320.webp', 3 * 86400);
        $this->file('variants/2026/10/'.self::UNKNOWN_UUID.'/thumb-320.webp', 3 * 86400);
        $this->file('notes.txt', 3 * 86400);

        $report = $this->checker()->check(false, false);

        self::assertSame(5, $report->files, 'intact, two damaged and two orphans below originals/; the missing file is not there');
        self::assertSame([$missing->getStorageKey()], $report->missing);
        self::assertEqualsCanonicalizing([$sameSizeOtherContent->getStorageKey(), $truncated->getStorageKey()], $report->damaged);
        self::assertSame(['originals/2026/10/0193a8f2-7c1e-7000-8000-000000000001.png', 'variants/2026/10/'.self::UNKNOWN_UUID], $report->orphansDeleted);
        self::assertSame(['originals/2026/11/0193a8f2-7c1e-7000-8000-000000000002.png'], $report->orphansKept);
        self::assertSame(1, $report->leftoversDeleted);
        self::assertSame(['notes.txt'], $report->unexpected);
        self::assertNull($report->deletionBlockedReason);

        self::assertFileDoesNotExist($oldOrphan);
        self::assertFileExists($freshOrphan);
        self::assertFileExists($ownVariant, 'variants of an existing media item stay');
        self::assertFileExists($this->path($intact));
        self::assertFileExists($this->path($sameSizeOtherContent), 'damaged files are only reported');
    }

    public function testVerifiedFilesGetTheirVerificationTime(): void
    {
        $media = MediaFactory::createOne();
        $this->setVerifiedAt($media, null);

        $this->checker()->check(false, false);

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->reload($media)->getVerifiedAt());
    }

    public function testTheHashBudgetChecksTheFilesVerifiedLongestAgoFirst(): void
    {
        $recent = MediaFactory::createOne();
        $oldest = MediaFactory::createOne();
        $this->setVerifiedAt($recent, '2026-11-20 10:00:00');
        $this->setVerifiedAt($oldest, '2026-10-01 10:00:00');

        $report = $this->checker(hashBudgetBytes: 1)->check(false, false);

        self::assertSame(1, $report->hashed);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->reload($oldest)->getVerifiedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 10:00:00'), $this->reload($recent)->getVerifiedAt());
    }

    public function testAFullCheckHashesEveryFile(): void
    {
        MediaFactory::createMany(3);

        self::assertSame(3, $this->checker(hashBudgetBytes: 1)->check(true, false)->hashed);
    }

    public function testNoOrphanIsDeletedWhenTheLibraryIsEmpty(): void
    {
        $orphan = $this->file('originals/2026/10/0193a8f2-7c1e-7000-8000-000000000001.png', 3 * 86400);

        $report = $this->checker()->check(false, false);

        self::assertSame(MediaIntegrityReport::BLOCKED_EMPTY_LIBRARY, $report->deletionBlockedReason);
        self::assertFileExists($orphan);
    }

    public function testNoOrphanIsDeletedWhenThereAreTooManyToBeLeftovers(): void
    {
        MediaFactory::createOne();
        for ($i = 10; $i < 21; ++$i) {
            $this->file(sprintf('originals/2026/10/0193a8f2-7c1e-7000-8000-0000000000%02d.png', $i), 3 * 86400);
        }

        $report = $this->checker()->check(false, false);

        self::assertSame(MediaIntegrityReport::BLOCKED_ORPHAN_SHARE, $report->deletionBlockedReason, '11 orphans of 12 files: more than 10 and more than 20 %');
        self::assertSame([], $report->orphansDeleted);
        self::assertCount(11, $report->orphansKept);
    }

    public function testDryRunDeletesAndStoresNothing(): void
    {
        MediaFactory::createOne();
        $orphan = $this->file('originals/2026/10/0193a8f2-7c1e-7000-8000-000000000001.png', 3 * 86400);

        $report = $this->checker()->check(false, true);

        self::assertSame(MediaIntegrityReport::BLOCKED_DRY_RUN, $report->deletionBlockedReason);
        self::assertFileExists($orphan);
        self::assertNull(self::getContainer()->get(MediaIntegrityCheckRepository::class)->latest());
    }

    public function testTheStoredResultDrivesTheHealthCheck(): void
    {
        $health = self::getContainer()->get(MediaHealthCheck::class);
        self::assertSame('unchecked', $health->check()->value);

        $media = MediaFactory::createOne();
        unlink($this->path($media));
        $this->checker()->check(false, false);

        $result = $health->check();
        self::assertSame(HealthCheckStatus::Degraded, $result->status);
        self::assertSame('missing=1 damaged=0', $result->value);
        self::assertFalse($result->public);
    }

    public function testRefusesWhileABackupHoldsTheFiles(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock(MediaStorageMigrator::LOCK, null);
        self::assertTrue($lock->acquire());

        try {
            $this->expectException(MediaMaintenanceException::class);
            $this->checker()->check(false, false);
        } finally {
            $lock->release();
        }
    }

    private function checker(int $hashBudgetBytes = 268_435_456): MediaIntegrityCheckerInterface
    {
        $container = self::getContainer();

        return new MediaIntegrityChecker(
            $this->storage(),
            $container->get(Connection::class),
            $container->get(MediaIntegrityCheckRepository::class),
            $container->get(LockFactory::class),
            $container->get(\Psr\Clock\ClockInterface::class),
            $container->get(EventDispatcherInterface::class),
            new NullLogger(),
            $hashBudgetBytes,
            24,
        );
    }

    /** Writes a file below the storage root, last changed $age seconds before "now". */
    private function file(string $path, int $age): string
    {
        $absolute = $this->storage()->root().'/'.$path;
        new Filesystem()->dumpFile($absolute, 'x');
        touch($absolute, $this->now - $age);
        if (str_starts_with($path, 'variants/')) {
            touch(\dirname($absolute), $this->now - $age);
        }

        return $absolute;
    }

    private function variantOf(Media $media): string
    {
        return substr($this->storage()->variantDirectory((string) $media->getStorageKey()), \strlen($this->storage()->root()) + 1);
    }

    private function path(Media $media): string
    {
        return $this->storage()->absolutePath((string) $media->getStorageKey());
    }

    private function setVerifiedAt(Media $media, ?string $verifiedAt): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE media SET verified_at = :at WHERE id = :id', ['at' => $verifiedAt, 'id' => $media->getId()]);
    }

    private function reload(Media $media): Media
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Media::class, $media->getId());
        self::assertInstanceOf(Media::class, $reloaded);

        return $reloaded;
    }

    private function storage(): MediaStorage
    {
        return self::getContainer()->get(MediaStorage::class);
    }
}
