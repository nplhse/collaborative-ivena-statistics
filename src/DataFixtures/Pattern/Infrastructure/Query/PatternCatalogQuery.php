<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Infrastructure\Query;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\DataFixtures\Pattern\Package\PatternCatalogNames;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The names and PZC codes of the reference catalog in the database, for the catalog check of a pattern package.
 */
final readonly class PatternCatalogQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function current(): PatternCatalogNames
    {
        return new PatternCatalogNames(
            indicationCodes: array_map(
                static fn (int|string $code): string => str_pad((string) $code, 3, '0', \STR_PAD_LEFT),
                $this->column(IndicationNormalized::class, 'code'),
            ),
            departments: $this->names(Department::class),
            occasions: $this->names(Occasion::class),
            assignments: $this->names(Assignment::class),
            infections: $this->names(Infection::class),
            secondaryTransports: $this->names(SecondaryTransport::class),
        );
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function names(string $class): array
    {
        return array_map(strval(...), $this->column($class, 'name'));
    }

    /**
     * @param class-string $class
     *
     * @return list<int|string>
     */
    private function column(string $class, string $field): array
    {
        /** @var list<int|string> $values */
        $values = $this->entityManager->createQueryBuilder()
            ->select(sprintf('DISTINCT e.%s', $field))
            ->from($class, 'e')
            ->getQuery()
            ->getSingleColumnResult();

        return $values;
    }
}
