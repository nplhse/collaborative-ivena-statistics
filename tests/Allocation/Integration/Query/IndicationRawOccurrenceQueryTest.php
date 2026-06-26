<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\IndicationRawOccurrenceQuery;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationRawOccurrenceQueryTest extends KernelTestCase
{
    use Factories;

    private IndicationRawOccurrenceQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(IndicationRawOccurrenceQuery::class);
    }

    public function testCountOpenAndBySegment(): void
    {
        IndicationRawFactory::createOne(['code' => 901, 'name' => 'Open Raw']);
        IndicationRawFactory::createOne([
            'code' => 902,
            'name' => 'Matched Raw',
            'reviewStatus' => IndicationRawReviewStatus::Matched,
        ]);

        self::assertSame(1, $this->query->countOpen());
        self::assertSame(1, $this->query->countBySegment('unreviewed'));
        self::assertSame(1, $this->query->countBySegment('matched'));
        self::assertSame(1, $this->query->countBySegment('unknown-segment'));
    }

    public function testFetchCountsForIdsReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], $this->query->fetchCountsForIds([]));
    }

    public function testFetchOccurrenceCountAndSampleAllocations(): void
    {
        $raw = IndicationRawFactory::createOne(['code' => 903, 'name' => 'Occurrence Raw']);
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdBy' => $user,
            'name' => 'Occurrence Hospital',
        ]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);

        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'gender' => AllocationGender::MALE,
            'transportType' => AllocationTransportType::GROUND,
            'urgency' => AllocationUrgency::EMERGENCY,
        ]);

        self::assertSame(1, $this->query->fetchOccurrenceCount((int) $raw->getId()));

        $samples = $this->query->fetchSampleAllocations((int) $raw->getId());
        self::assertCount(1, $samples);
        self::assertSame('Occurrence Hospital', $samples[0]['hospital_name']);
    }
}
