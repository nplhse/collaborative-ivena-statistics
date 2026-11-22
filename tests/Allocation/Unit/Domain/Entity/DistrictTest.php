<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Domain\Entity;

use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DistrictTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testDerivesTheStateKeyFromTheAgs(): void
    {
        $district = new District('06411', 'Darmstadt');

        self::assertSame('06411', $district->getAgs());
        self::assertSame('06', $district->getStateCode());
        self::assertNull($district->getDispatchArea());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['0641'];
        yield 'too long' => ['064110'];
        yield 'letters' => ['06A11'];
        yield 'trailing newline' => ["06411\n"];
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsAnInvalidAgs(string $ags): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new District($ags, 'Darmstadt');
    }

    public function testUpdateSetsTheTimestamp(): void
    {
        self::mockTime('2026-11-22 12:00:00');
        $district = new District('06411', 'Darmstadt');

        self::mockTime('2026-11-23 08:30:00');
        $district->updateTimestamps();

        self::assertEquals(new \DateTimeImmutable('2026-11-23 08:30:00'), $district->getUpdatedAt());
    }

    public function testStoringAGeometryKeepsItsProvenance(): void
    {
        $geometry = new DistrictGeometry(new District('06411', 'Darmstadt'));
        $fetchedAt = new \DateTimeImmutable('2026-11-22 12:00:00');

        $geometry->storeGeometry('{"type":"Polygon"}', 'bkg', 'dl-de/by-2-0', '© BKG (2026) dl-de/by-2-0', $fetchedAt);

        self::assertSame('{"type":"Polygon"}', $geometry->getGeometry());
        self::assertSame('bkg', $geometry->getGeometryProvider());
        self::assertSame('dl-de/by-2-0', $geometry->getGeometryLicence());
        self::assertSame('© BKG (2026) dl-de/by-2-0', $geometry->getGeometryAttribution());
        self::assertSame($fetchedAt, $geometry->getGeometryFetchedAt());
        self::assertSame(DistrictBoundaryFetchStatus::Idle, $geometry->getFetchStatus());
        self::assertNull($geometry->getFetchAttemptId());
    }
}
