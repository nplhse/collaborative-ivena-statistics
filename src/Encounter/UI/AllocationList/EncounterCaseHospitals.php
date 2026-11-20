<?php

declare(strict_types=1);

namespace App\Encounter\UI\AllocationList;

use App\Encounter\Contract\Access\EncounterAccessInterface;
use App\Encounter\Domain\Entity\Encounter;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ids of the hospitals whose case-level encounter data the current user may see (ADR 028), read once per request
 * for the many rows of the Explore allocation list. The rule is EncounterAccessInterface::hospitalsWithViewCases():
 * the beta role is required; grants with VIEW_CASES (also set by REVIEW) count; ROLE_ADMIN gets every hospital with
 * encounter data. Hospital ownership and hospital grants never imply it.
 */
final class EncounterCaseHospitals implements ResetInterface
{
    /** @var array<int, list<int>> hospital ids by user id */
    private array $byUser = [];

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private readonly EncounterAccessInterface $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<int>
     */
    public function forUser(User $user): array
    {
        $userId = (int) $user->getId();
        if (!\array_key_exists($userId, $this->byUser)) {
            $this->byUser[$userId] = array_map(
                static fn (array $hospital): int => $hospital['id'],
                $this->access->hospitalsWithViewCases(),
            );
        }

        return $this->byUser[$userId];
    }

    public function maySee(User $user, ?int $hospitalId): bool
    {
        return null !== $hospitalId && \in_array($hospitalId, $this->forUser($user), true);
    }

    /**
     * The given encounters the user may see, each checked by its own hospital; unknown ids are dropped.
     *
     * @param list<int> $encounterIds
     *
     * @return list<int>
     */
    public function visibleEncounters(User $user, array $encounterIds): array
    {
        $hospitals = $this->forUser($user);
        if ([] === $encounterIds || [] === $hospitals) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('e.id')
            ->from(Encounter::class, 'e')
            ->where('e.id IN (:encounterIds)')
            ->andWhere('e.hospital IN (:hospitalIds)')
            ->setParameter('encounterIds', $encounterIds, ArrayParameterType::INTEGER)
            ->setParameter('hospitalIds', $hospitals, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    #[\Override]
    public function reset(): void
    {
        $this->byUser = [];
    }
}
