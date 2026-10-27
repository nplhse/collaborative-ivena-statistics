<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Domain\Entity;

use App\Allocation\Domain\Entity\Assessment;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class EntityTimestampsTest extends TestCase
{
    use ClockSensitiveTrait;

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function entitiesWithUpdatedAt(): iterable
    {
        yield 'Assignment' => [Assignment::class];
        yield 'Department' => [Department::class];
        yield 'DispatchArea' => [DispatchArea::class];
        yield 'Hospital' => [Hospital::class];
        yield 'HospitalAccessGrant' => [HospitalAccessGrant::class];
        yield 'IndicationGroup' => [IndicationGroup::class];
        yield 'IndicationNormalized' => [IndicationNormalized::class];
        yield 'IndicationRaw' => [IndicationRaw::class];
        yield 'Infection' => [Infection::class];
        yield 'Occasion' => [Occasion::class];
        yield 'SecondaryTransport' => [SecondaryTransport::class];
        yield 'Speciality' => [Speciality::class];
        yield 'State' => [State::class];
    }

    /**
     * @param class-string<Assignment|Department|DispatchArea|Hospital|HospitalAccessGrant|IndicationGroup|IndicationNormalized|IndicationRaw|Infection|Occasion|SecondaryTransport|Speciality|State> $class
     */
    #[DataProvider('entitiesWithUpdatedAt')]
    public function testTimestampsFollowTheMockedClock(string $class): void
    {
        self::mockTime(new \DateTimeImmutable('2026-02-03 04:05:06'));
        $entity = new $class();

        self::assertSame('2026-02-03 04:05:06', $entity->getCreatedAt()?->format('Y-m-d H:i:s'));

        self::mockTime(new \DateTimeImmutable('2026-02-10 08:00:00'));
        $entity->updateTimestamps();

        self::assertSame('2026-02-10 08:00:00', $entity->getUpdatedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAssessmentCreatedAtFollowsTheMockedClock(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-02-03 04:05:06'));

        self::assertSame('2026-02-03 04:05:06', new Assessment()->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testHospitalParticipatingSinceFollowsTheMockedClock(): void
    {
        $hospital = new Hospital();
        self::mockTime(new \DateTimeImmutable('2026-04-01 10:00:00'));

        $hospital->setIsParticipating(true);

        self::assertSame('2026-04-01 10:00:00', $hospital->getParticipatingSince()?->format('Y-m-d H:i:s'));
    }
}
