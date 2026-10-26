<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\IndicationNormalizedListStatsQuery;
use App\Allocation\Infrastructure\Query\IndicationRawOccurrenceQuery;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Allocation\UI\Http\DTO\IndicationQueryParametersDTO;
use App\Allocation\UI\Http\DTO\IndicationRawReviewWorklistQueryDTO;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationListSortingTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        $state = StateFactory::createOne();
        DispatchAreaFactory::createOne(['state' => $state]);
        HospitalFactory::createOne();
        ImportFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
    }

    public function testRawIndicationsSortByPrimaryPlusSecondaryOccurrence(): void
    {
        $rare = IndicationRawFactory::createOne(['code' => 1, 'name' => 'Rare']);
        $frequent = IndicationRawFactory::createOne(['code' => 2, 'name' => 'Frequent']);
        $unused = IndicationRawFactory::createOne(['code' => 3, 'name' => 'Unused']);
        $secondaryOnly = IndicationRawFactory::createOne(['code' => 4, 'name' => 'Secondary only']);

        AllocationFactory::createMany(3, ['indicationRaw' => $frequent]);
        AllocationFactory::createOne(['indicationRaw' => $rare, 'secondaryIndicationRaw' => $frequent]);
        AllocationFactory::createMany(2, ['indicationRaw' => $rare, 'secondaryIndicationRaw' => $secondaryOnly]);

        // Frequent 3+1, Rare 3, Secondary only 0+2, Unused 0
        self::assertSame(
            ['Frequent', 'Rare', 'Secondary only', 'Unused'],
            $this->rawNames(new IndicationQueryParametersDTO(orderBy: 'desc', sortBy: 'occurrence', type: 'raw')),
        );

        $counts = self::getContainer()->get(IndicationRawOccurrenceQuery::class)->fetchCountsForIds([
            (int) $frequent->getId(),
            (int) $secondaryOnly->getId(),
            (int) $unused->getId(),
        ]);
        self::assertSame(['occurrence_count' => 4, 'primary_count' => 3, 'secondary_count' => 1], $counts[(int) $frequent->getId()]);
        self::assertSame(['occurrence_count' => 2, 'primary_count' => 0, 'secondary_count' => 2], $counts[(int) $secondaryOnly->getId()]);
        self::assertSame(0, $counts[(int) $unused->getId()]['occurrence_count']);
    }

    public function testWorklistSortsByOccurrenceWithTheSameCount(): void
    {
        $rare = IndicationRawFactory::createOne(['code' => 1, 'name' => 'Rare']);
        $frequent = IndicationRawFactory::createOne(['code' => 2, 'name' => 'Frequent']);
        AllocationFactory::createOne(['indicationRaw' => $rare]);
        AllocationFactory::createOne(['indicationRaw' => $rare, 'secondaryIndicationRaw' => $frequent]);
        AllocationFactory::createMany(2, ['indicationRaw' => $frequent]);

        /** @var list<IndicationRaw> $rows */
        $rows = self::getContainer()->get(IndicationRawRepository::class)
            ->reviewWorklistQuery(new IndicationRawReviewWorklistQueryDTO(orderBy: 'desc', sortBy: 'occurrence'))
            ->getQuery()
            ->getResult();

        self::assertSame(['Frequent', 'Rare'], array_map(static fn (IndicationRaw $raw): ?string => $raw->getName(), $rows));
    }

    public function testNormalizedIndicationsSortByOccurrenceAndRawCount(): void
    {
        $few = IndicationNormalizedFactory::createOne(['code' => 1, 'name' => 'Few']);
        $many = IndicationNormalizedFactory::createOne(['code' => 2, 'name' => 'Many']);
        IndicationRawFactory::createMany(3, ['target' => $few]);
        IndicationRawFactory::createOne(['target' => $many]);
        AllocationFactory::createMany(2, ['indicationNormalized' => $many]);
        AllocationFactory::createOne(['indicationNormalized' => $few, 'secondaryIndicationNormalized' => $many]);

        self::assertSame(['Many', 'Few'], $this->normalizedNames(new IndicationQueryParametersDTO(orderBy: 'desc', sortBy: 'occurrence')));
        self::assertSame(['Few', 'Many'], $this->normalizedNames(new IndicationQueryParametersDTO(orderBy: 'desc', sortBy: 'rawCount')));

        $stats = self::getContainer()->get(IndicationNormalizedListStatsQuery::class);
        $ids = [(int) $few->getId(), (int) $many->getId()];
        self::assertSame(3, $stats->fetchOccurrenceCounts($ids)[(int) $many->getId()]['occurrence_count']);
        // The counts come from a GROUP BY without an order, so only the pairs are compared.
        self::assertEquals([(int) $few->getId() => 3, (int) $many->getId() => 1], $stats->fetchRawCounts($ids));
    }

    public function testNormalizedGroupNamesFollowGroupOrder(): void
    {
        $indication = IndicationNormalizedFactory::createOne(['code' => 1, 'name' => 'Grouped']);
        $plain = IndicationNormalizedFactory::createOne(['code' => 2, 'name' => 'Plain']);
        IndicationGroupFactory::createOne(['name' => 'Zeta', 'sortOrder' => 1, 'indications' => [$indication]]);
        IndicationGroupFactory::createOne(['name' => 'Alpha', 'sortOrder' => 2, 'indications' => [$indication]]);

        $stats = self::getContainer()->get(IndicationNormalizedListStatsQuery::class);

        self::assertSame(
            [(int) $indication->getId() => ['Zeta', 'Alpha']],
            $stats->fetchGroupNames([(int) $indication->getId(), (int) $plain->getId()]),
        );
        self::assertSame([], $stats->fetchGroupNames([]));
        self::assertSame([], $stats->fetchRawCounts([]));
        self::assertSame([], $stats->fetchOccurrenceCounts([]));
    }

    /**
     * @return list<string|null>
     */
    private function rawNames(IndicationQueryParametersDTO $query): array
    {
        /** @var list<IndicationRaw> $rows */
        $rows = self::getContainer()->get(IndicationRawRepository::class)->listQuery($query)->getQuery()->getResult();

        return array_map(static fn (IndicationRaw $raw): ?string => $raw->getName(), $rows);
    }

    /**
     * @return list<string|null>
     */
    private function normalizedNames(IndicationQueryParametersDTO $query): array
    {
        /** @var list<IndicationNormalized> $rows */
        $rows = self::getContainer()->get(IndicationNormalizedRepository::class)->listQuery($query)->getQuery()->getResult();

        return array_map(static fn (IndicationNormalized $indication): ?string => $indication->getName(), $rows);
    }
}
