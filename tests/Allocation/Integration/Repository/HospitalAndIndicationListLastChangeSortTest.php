<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\IndicationKey;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Allocation\UI\Http\DTO\HospitalQueryParametersDTO;
use App\Allocation\UI\Http\DTO\IndicationQueryParametersDTO;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Covers the `lastChange` branch of the hospital and indication list queries (#1210).
 *
 * The hidden sort key is `updatedAt` when set, otherwise `createdAt`. The fixture matches
 * CatalogListLastChangeSortTest; the indications get fixed codes:
 *
 * | name             | code | createdAt  | updatedAt  | sort key   |
 * |------------------|------|------------|------------|------------|
 * | Recently updated | 100  | 2026-01-01 | 2026-03-01 | 2026-03-01 |
 * | Updated long ago | 200  | 2025-11-01 | 2026-01-15 | 2026-01-15 |
 * | Never updated    | 300  | 2026-02-01 | null       | 2026-02-01 |
 *
 * Ascending by sort key: Updated long ago, Never updated, Recently updated. This order differs from
 * the order by name, code, id, `createdAt` alone and `updatedAt` alone, so only the `lastChange`
 * branch produces it.
 */
#[ResetDatabase]
final class HospitalAndIndicationListLastChangeSortTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const array ROWS = [
        ['name' => 'Recently updated', 'code' => 100, 'createdAt' => '2026-01-01 08:00:00', 'updatedAt' => '2026-03-01 08:00:00'],
        ['name' => 'Updated long ago', 'code' => 200, 'createdAt' => '2025-11-01 08:00:00', 'updatedAt' => '2026-01-15 08:00:00'],
        ['name' => 'Never updated', 'code' => 300, 'createdAt' => '2026-02-01 08:00:00', 'updatedAt' => null],
    ];

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime('2026-05-15 10:00:00');
        self::bootKernel();
    }

    /**
     * @param \Closure(array<string, mixed>, int): void          $create
     * @param \Closure(ContainerInterface, string): QueryBuilder $listQuery
     */
    #[DataProvider('lists')]
    public function testSortsByLastChangeAscending(\Closure $create, \Closure $listQuery): void
    {
        $this->createRows($create);

        self::assertSame(
            ['Updated long ago', 'Never updated', 'Recently updated'],
            $this->names($listQuery(self::getContainer(), 'asc')),
        );
    }

    /**
     * @param \Closure(array<string, mixed>, int): void          $create
     * @param \Closure(ContainerInterface, string): QueryBuilder $listQuery
     */
    #[DataProvider('lists')]
    public function testSortsByLastChangeDescending(\Closure $create, \Closure $listQuery): void
    {
        $this->createRows($create);

        self::assertSame(
            ['Recently updated', 'Never updated', 'Updated long ago'],
            $this->names($listQuery(self::getContainer(), 'desc')),
        );
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>, int): void, \Closure(ContainerInterface, string): QueryBuilder}>
     */
    public static function lists(): iterable
    {
        yield 'hospital' => [
            static function (array $attributes, int $code): void { HospitalFactory::createOne($attributes); },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(HospitalRepository::class)
                ->hospitalListQuery(new HospitalQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];

        yield 'raw indication' => [
            static function (array $attributes, int $code): void {
                IndicationRawFactory::createOne([
                    ...$attributes,
                    'code' => $code,
                    'hash' => IndicationKey::hashFrom((string) $code, $attributes['name']),
                ]);
            },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(IndicationRawRepository::class)
                ->listQuery(new IndicationQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange', type: 'raw')),
        ];

        yield 'normalized indication' => [
            static function (array $attributes, int $code): void {
                IndicationNormalizedFactory::createOne([...$attributes, 'code' => $code]);
            },
            static fn (ContainerInterface $c, string $orderBy): QueryBuilder => $c->get(IndicationNormalizedRepository::class)
                ->listQuery(new IndicationQueryParametersDTO(orderBy: $orderBy, sortBy: 'lastChange')),
        ];
    }

    /**
     * @param \Closure(array<string, mixed>, int): void $create
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
            ], $row['code']);
        }
    }

    /**
     * @return list<string|null>
     */
    private function names(QueryBuilder $queryBuilder): array
    {
        /** @var list<Hospital|IndicationNormalized|IndicationRaw> $rows */
        $rows = $queryBuilder->getQuery()->getResult();

        return array_map(static fn (Hospital|IndicationNormalized|IndicationRaw $row): ?string => $row->getName(), $rows);
    }
}
