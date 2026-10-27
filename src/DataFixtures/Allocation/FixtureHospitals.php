<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hospitals of the loaded reference catalog, for the allocation fixtures.
 */
final readonly class FixtureHospitals
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Hospital>
     */
    public function allHospitals(): array
    {
        return $this->entityManager->getRepository(Hospital::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<Hospital>
     */
    public function hospitalsMatching(?HospitalTier $tier, ?HospitalLocation $location): array
    {
        return array_values(array_filter(
            $this->allHospitals(),
            static function (Hospital $hospital) use ($tier, $location): bool {
                if ($tier instanceof HospitalTier && $hospital->getTier() !== $tier) {
                    return false;
                }
                if ($location instanceof HospitalLocation && $hospital->getLocation() !== $location) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * Stratified subset: spread across size tiers, prefer participating hospitals.
     *
     * @param list<Hospital> $required
     *
     * @return list<Hospital>
     */
    public function selectActiveHospitals(int $count, array $required = []): array
    {
        $allHospitals = $this->allHospitals();

        $participating = array_values(array_filter(
            $allHospitals,
            static fn (Hospital $hospital): bool => $hospital->isParticipating(),
        ));
        if ([] === $participating) {
            return [];
        }

        $selected = $this->uniqueHospitals(array_values(array_filter(
            $required,
            static fn (Hospital $hospital): bool => $hospital->isParticipating(),
        )));

        if (\count($selected) >= $count) {
            return \array_slice($selected, 0, $count);
        }

        if ($count >= \count($participating)) {
            return $this->uniqueHospitals([...$selected, ...$participating]);
        }

        $selectedIds = $this->hospitalIds($selected);
        $pool = array_values(array_filter(
            $participating,
            static fn (Hospital $hospital): bool => !isset($selectedIds[(int) $hospital->getId()]),
        ));

        usort($pool, static fn (Hospital $a, Hospital $b): int => strcmp((string) $a->getName(), (string) $b->getName()));

        /** @var array<string, list<Hospital>> $bySize */
        $bySize = [];
        foreach ($pool as $hospital) {
            $size = ($hospital->getSize() ?? HospitalSize::MEDIUM)->value;
            $bySize[$size][] = $hospital;
        }

        $sizes = [HospitalSize::LARGE->value, HospitalSize::MEDIUM->value, HospitalSize::SMALL->value];
        $sizeIndex = 0;

        while (\count($selected) < $count) {
            $size = $sizes[$sizeIndex % \count($sizes)];
            if (isset($bySize[$size]) && [] !== $bySize[$size]) {
                $selected[] = array_shift($bySize[$size]);
            }
            ++$sizeIndex;
            if ($sizeIndex > $count * 3 && [] === array_filter($bySize)) {
                break;
            }
        }

        if (\count($selected) < $count) {
            foreach ($pool as $hospital) {
                if (isset($this->hospitalIds($selected)[(int) $hospital->getId()])) {
                    continue;
                }
                $selected[] = $hospital;
                if (\count($selected) >= $count) {
                    break;
                }
            }
        }

        return \array_slice($this->uniqueHospitals($selected), 0, $count);
    }

    /**
     * @return list<Hospital>
     */
    public function participatingHospitals(): array
    {
        return array_values(array_filter(
            $this->allHospitals(),
            static fn (Hospital $hospital): bool => $hospital->isParticipating(),
        ));
    }

    /**
     * @return list<Hospital>
     */
    public function participatingHospitalsMatching(?HospitalTier $tier, ?HospitalLocation $location): array
    {
        return array_values(array_filter(
            $this->participatingHospitals(),
            static function (Hospital $hospital) use ($tier, $location): bool {
                if ($tier instanceof HospitalTier && $hospital->getTier() !== $tier) {
                    return false;
                }
                if ($location instanceof HospitalLocation && $hospital->getLocation() !== $location) {
                    return false;
                }

                return true;
            },
        ));
    }

    public function findHospitalOwnedByUsername(string $username): ?Hospital
    {
        $hospital = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(Hospital::class, 'h')
            ->join('h.owner', 'u')
            ->where('u.username = :username')
            ->setParameter('username', $username)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $hospital instanceof Hospital ? $hospital : null;
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return list<Hospital>
     */
    private function uniqueHospitals(array $hospitals): array
    {
        $unique = [];
        foreach ($hospitals as $hospital) {
            $unique[(int) $hospital->getId()] = $hospital;
        }

        return array_values($unique);
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return array<int, true>
     */
    private function hospitalIds(array $hospitals): array
    {
        $ids = [];
        foreach ($hospitals as $hospital) {
            $ids[(int) $hospital->getId()] = true;
        }

        return $ids;
    }
}
