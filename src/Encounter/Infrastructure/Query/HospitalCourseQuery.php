<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\HospitalCourseReaderInterface;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Effective link of one allocation with its visit for the case card (#1055). The partial unique index
 * `uniq_encounter_match_effective_allocation` guarantees at most one row.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of HospitalCourseReaderInterface.
 */
final readonly class HospitalCourseQuery implements HospitalCourseReaderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function effectiveLinkOfAllocation(int $allocationId): ?EncounterMatch
    {
        /** @var EncounterMatch|null $link */
        $link = $this->entityManager->createQueryBuilder()
            ->select('m', 'e', 'd')
            ->from(EncounterMatch::class, 'm')
            ->join('m.encounter', 'e')
            ->leftJoin('e.admittingDepartment', 'd')
            ->where('IDENTITY(m.allocation) = :allocation')
            ->andWhere('m.status IN (:effective)')
            ->setParameter('allocation', $allocationId)
            ->setParameter('effective', MatchStatus::effective())
            ->getQuery()
            ->getOneOrNullResult();

        return $link;
    }
}
