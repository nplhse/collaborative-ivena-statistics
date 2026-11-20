<?php

declare(strict_types=1);

namespace App\Encounter\UI\AllocationList;

use App\Encounter\Contract\Access\EncounterAccessInterface;
use App\User\Domain\Entity\User;
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

    #[\Override]
    public function reset(): void
    {
        $this->byUser = [];
    }
}
