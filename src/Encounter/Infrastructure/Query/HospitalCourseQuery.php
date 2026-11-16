<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\HospitalCourseReaderInterface;
use App\Encounter\Domain\Entity\EdVisit;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Visits and their ED matching links for the hospital course (#1055, #1178). The partial unique indexes
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
    public function visitByUid(Uuid $visitUid): ?EdVisit
    {
        /** @var EdVisit|null $visit */
        $visit = $this->entityManager->createQueryBuilder()
            ->select('v', 'e', 'h', 'd')
            ->from(EdVisit::class, 'v')
            ->join('v.encounter', 'e')
            ->join('v.hospital', 'h')
            ->leftJoin('v.admittingDepartment', 'd')
            ->where('v.visitUid = :uid')
            ->setParameter('uid', $visitUid, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $visit;
    }

    #[\Override]
    public function visitOfEncounter(int $encounterId): ?EdVisit
    {
        /** @var EdVisit|null $visit */
        $visit = $this->entityManager->createQueryBuilder()
            ->select('v', 'e', 'd')
            ->from(EdVisit::class, 'v')
            ->join('v.encounter', 'e')
            ->leftJoin('v.admittingDepartment', 'd')
            ->where('e.id = :encounter')
            ->setParameter('encounter', $encounterId, Types::INTEGER)
            ->getQuery()
            ->getOneOrNullResult();

        return $visit;
    }

    #[\Override]
    public function effectiveLinkOfVisit(EdVisit $visit): ?EncounterMatch
    {
        /** @var EncounterMatch|null $link */
        $link = $this->entityManager->createQueryBuilder()
            ->select('m', 'a')
            ->from(EncounterMatch::class, 'm')
            ->join('m.allocation', 'a')
            ->where('IDENTITY(m.encounter) = :encounter')
            ->andWhere('m.status IN (:effective)')
            ->setParameter('encounter', (int) $visit->getId(), Types::INTEGER)
            ->setParameter('effective', MatchStatus::effective())
            ->getQuery()
            ->getOneOrNullResult();

        return $link;
    }

    #[\Override]
    public function openProposalsOfVisit(EdVisit $visit): array
    {
        /** @var list<EncounterMatch> $proposals */
        $proposals = $this->entityManager->createQueryBuilder()
            ->select('m', 'a')
            ->from(EncounterMatch::class, 'm')
            ->join('m.allocation', 'a')
            ->where('IDENTITY(m.encounter) = :encounter')
            ->andWhere('m.status = :proposed')
            ->setParameter('encounter', (int) $visit->getId(), Types::INTEGER)
            ->setParameter('proposed', MatchStatus::Proposed)
            ->orderBy('m.score', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $proposals;
    }
}
