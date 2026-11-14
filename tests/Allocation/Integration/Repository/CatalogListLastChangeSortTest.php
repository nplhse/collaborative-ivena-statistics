<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Repository\AssignmentRepository;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Allocation\Infrastructure\Repository\OccasionRepository;
use App\Allocation\Infrastructure\Repository\SecondaryTransportRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Allocation\UI\Http\DTO\AreaListQueryParametersDTO;
use App\Allocation\UI\Http\DTO\AssignmentQueryParametersDTO;
use App\Allocation\UI\Http\DTO\InfectionQueryParametersDTO;
use App\Allocation\UI\Http\DTO\OccasionQueryParametersDTO;
use App\Allocation\UI\Http\DTO\SecondaryTransportQueryParametersDTO;
use App\Allocation\UI\Http\DTO\SpecialityQueryParametersDTO;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Covers the `lastChange` branch of the catalog list queries (#1149).
 *
 * The hidden sort key is `updatedAt` when set, otherwise `createdAt`. The fixture:
 *
 * | name             | createdAt  | updatedAt  | sort key   |
 * |------------------|------------|------------|------------|
 * | Recently updated | 2026-01-01 | 2026-03-01 | 2026-03-01 |
 * | Updated long ago | 2025-11-01 | 2026-01-15 | 2026-01-15 |
 * | Never updated    | 2026-02-01 | null       | 2026-02-01 |
 *
 * Ascending by sort key: Updated long ago, Never updated, Recently updated. This order differs from
 * the order by name, id, `createdAt` alone and `updatedAt` alone, so only the `lastChange` branch
 * produces it.
 */
#[ResetDatabase]
final class CatalogListLastChangeSortTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const array ROWS = [
        ['name' => 'Recently updated', 'createdAt' => '2026-01-01 08:00:00', 'updatedAt' => '2026-03-01 08:00:00'],
        ['name' => 'Updated long ago', 'createdAt' => '2025-11-01 08:00:00', 'updatedAt' => '2026-01-15 08:00:00'],
        ['name' => 'Never updated', 'createdAt' => '2026-02-01 08:00:00', 'updatedAt' => null],
    ];

    private const array ASCENDING = ['Updated long ago', 'Never updated', 'Recently updated'];

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime('2026-05-15 10:00:00');
        self::bootKernel();
    }

    /**
     * @param \Closure(array<string, mixed>): void               $create
     * @param \Closure(ContainerInterface, string): QueryBuilder $listQuery
     */
    #[DataProvider('catalogs')]
    public function testSortsByLastChangeAscending(\Closure $create, \Closure $listQuery): void
    {
        $this->createRows($create);

        self::assertSame(self::ASCENDING, $this->names($listQuery(self::getContainer(), 'asc')));
    }

    /**
     * @param \Closure(array<string, mixed>): void               $create
     * @param \Closure(ContainerInterface, string): QueryBuilder $listQuery
     */
    #[DataProvider('catalogs')]
    public function testSortsByLastChangeDescending(\Closure $create, \Closure $listQuery): void
    {
        $this->createRows($create);

        self::assertSame(array_reverse(self::ASCENDING), $this->names($listQuery(self::getContainer(), 'desc')));
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): void, \Closure(ContainerInterface, string): QueryBuilder}>
     */
    public static function catalogs(): iterable
    {
        yield 'assignment' => [
            static function (array $attributes): void { AssignmentFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(AssignmentRepository::class)
                ->listQuery(new AssignmentQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'department' => [
            static function (array $attributes): void { DepartmentFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(DepartmentRepository::class)
                ->listQuery(new SpecialityQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'dispatch area' => [
            static function (array $attributes): void {
                DispatchAreaFactory::createOne([...$attributes, 'state' => StateFactory::randomOrCreate()]);
            },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(DispatchAreaRepository::class)
                ->areaListQuery(new AreaListQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'indication group' => [
            static function (array $attributes): void { IndicationGroupFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(IndicationGroupRepository::class)
                ->listQuery(new SpecialityQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'infection' => [
            static function (array $attributes): void { InfectionFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(InfectionRepository::class)
                ->listQuery(new InfectionQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'occasion' => [
            static function (array $attributes): void { OccasionFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(OccasionRepository::class)
                ->listQuery(new OccasionQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'secondary transport' => [
            static function (array $attributes): void { SecondaryTransportFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(SecondaryTransportRepository::class)
                ->listQuery(new SecondaryTransportQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'speciality' => [
            static function (array $attributes): void { SpecialityFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(SpecialityRepository::class)
                ->listQuery(new SpecialityQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'state' => [
            static function (array $attributes): void { StateFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(StateRepository::class)
                ->listQuery(new SpecialityQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];
    }

    /**
     * @param \Closure(array<string, mixed>): void $create
     */
    private function createRows(\Closure $create): void
    {
        $user = UserFactory::createOne();

        foreach (self::ROWS as $row) {
            $create([
                'name' => $row['name'],
                'createdAt' => new \DateTimeImmutable($row['createdAt']),
                'updatedAt' => null === $row['updatedAt'] ? null : new \DateTimeImmutable($row['updatedAt']),
                'createdBy' => $user,
            ]);
        }
    }

    /**
     * @return list<string|null>
     */
    private function names(QueryBuilder $queryBuilder): array
    {
        /** @var list<Assignment|Department|DispatchArea|IndicationGroup|Infection|Occasion|SecondaryTransport|Speciality|State> $rows */
        $rows = $queryBuilder->getQuery()->getResult();

        return array_map(static fn (object $row): ?string => $row->getName(), $rows);
    }
}
