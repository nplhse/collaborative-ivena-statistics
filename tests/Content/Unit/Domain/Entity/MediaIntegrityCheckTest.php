<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain\Entity;

use App\Content\Domain\Entity\MediaIntegrityCheck;
use PHPUnit\Framework\TestCase;

final class MediaIntegrityCheckTest extends TestCase
{
    public function testKeepsTheCountsOfARun(): void
    {
        $check = $this->check(missing: 1, damaged: 2, findings: [['kind' => 'missing', 'key' => 'originals/2026/11/a.png']]);

        self::assertNull($check->getId());
        self::assertEquals(new \DateTimeImmutable('2026-11-21 05:10'), $check->getCheckedAt());
        self::assertTrue($check->isFull());
        self::assertSame(12, $check->getFiles());
        self::assertSame(4096, $check->getBytes());
        self::assertSame(5, $check->getHashed());
        self::assertSame(1, $check->getMissing());
        self::assertSame(2, $check->getDamaged());
        self::assertSame(3, $check->getOrphansDeleted());
        self::assertSame(4, $check->getOrphansKept());
        self::assertSame(6, $check->getUnexpected());
        self::assertSame('orphan_share', $check->getDeletionBlockedReason());
        self::assertSame([['kind' => 'missing', 'key' => 'originals/2026/11/a.png']], $check->getFindings());
    }

    public function testBytesFromTheDatabaseAreReadAsInteger(): void
    {
        self::assertSame(4096, $this->check(bytes: '4096')->getBytes());
    }

    public function testOnlyMissingAndDamagedFilesAreProblems(): void
    {
        self::assertFalse($this->check(missing: 0, damaged: 0)->hasProblems(), 'orphans and unexpected files are no problem of the library');
        self::assertTrue($this->check(missing: 1, damaged: 0)->hasProblems());
        self::assertTrue($this->check(missing: 0, damaged: 1)->hasProblems());
    }

    public function testStoresOnlyTheFirstFindings(): void
    {
        $findings = array_map(static fn (int $i): array => ['kind' => 'missing', 'key' => 'originals/2026/11/'.$i.'.png'], range(1, 60));

        $stored = $this->check(missing: 60, findings: $findings)->getFindings();

        self::assertCount(MediaIntegrityCheck::MAX_FINDINGS, $stored);
        self::assertSame('originals/2026/11/50.png', $stored[49]['key']);
    }

    /**
     * @param list<array{kind: string, key: string}> $findings
     */
    private function check(int $missing = 0, int $damaged = 0, int|string $bytes = 4096, array $findings = []): MediaIntegrityCheck
    {
        return new MediaIntegrityCheck(new \DateTimeImmutable('2026-11-21 05:10'), true, 12, $bytes, 5, $missing, $damaged, 3, 4, 6, 'orphan_share', $findings);
    }
}
