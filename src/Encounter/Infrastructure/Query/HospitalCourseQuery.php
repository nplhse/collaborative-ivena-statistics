<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\HospitalCourseReaderInterface;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Visits and links for the hospital course (#1055, #1178). The partial unique indexes
 * `uniq_encounter_match_effective_allocation` and `uniq_encounter_match_effective_encounter` guarantee at
 * most one effective link per allocation and per visit.
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

    #[\Override]
    public function visitByUid(Uuid $visitUid): ?Encounter
    {
        /** @var Encounter|null $encounter */
        $encounter = $this->entityManager->createQueryBuilder()
            ->select('e', 'h', 'd')
            ->from(Encounter::class, 'e')
            ->join('e.hospital', 'h')
            ->leftJoin('e.admittingDepartment', 'd')
            ->where('e.visitUid = :uid')
            ->setParameter('uid', $visitUid, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $encounter;
    }

    #[\Override]
    public function effectiveLinkOfVisit(Encounter $encounter): ?EncounterMatch
    {
        /** @var EncounterMatch|null $link */
        $link = $this->entityManager->createQueryBuilder()
            ->select('m', 'a')
            ->from(EncounterMatch::class, 'm')
            ->join('m.allocation', 'a')
            ->where('IDENTITY(m.encounter) = :encounter')
            ->andWhere('m.status IN (:effective)')
            ->setParameter('encounter', (int) $encounter->getId(), Types::INTEGER)
            ->setParameter('effective', MatchStatus::effective())
            ->getQuery()
            ->getOneOrNullResult();

        return $link;
    }

    #[\Override]
    public function openProposalsOfVisit(Encounter $encounter): array
    {
        /** @var list<EncounterMatch> $proposals */
        $proposals = $this->entityManager->createQueryBuilder()
            ->select('m', 'a')
            ->from(EncounterMatch::class, 'm')
            ->join('m.allocation', 'a')
            ->where('IDENTITY(m.encounter) = :encounter')
            ->andWhere('m.status = :proposed')
            ->setParameter('encounter', (int) $encounter->getId(), Types::INTEGER)
            ->setParameter('proposed', MatchStatus::Proposed)
            ->orderBy('m.score', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $proposals;
    }
}
