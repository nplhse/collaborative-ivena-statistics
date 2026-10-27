<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\FixtureVolume;

/**
 * Chooses the participating hospitals that receive synthetic allocations, and the demo hospital of `foo`.
 *
 * The profile says how many hospitals of each size take part. A large hospital is preferably a
 * full-care one, a medium one extended care and a small one basic care, so each profile covers all
 * volumes and all patterns. Both choices depend only on the hospitals and the seed, so the
 * participation fixture and the allocation generator agree.
 */
final readonly class FixtureHospitalSelection
{
    public const string ANY = 'any';

    private const array PREFERRED_TIER = [
        'Large' => HospitalTier::FULL,
        'Medium' => HospitalTier::EXTENDED,
        'Small' => HospitalTier::BASIC,
    ];

    /**
     * @param list<Hospital> $participating
     *
     * @return list<Hospital>
     */
    public function active(FixtureVolume $volume, array $participating): array
    {
        $selected = [];

        foreach (self::PREFERRED_TIER as $size => $tier) {
            $candidates = array_values(array_filter(
                $participating,
                static fn (Hospital $hospital): bool => $size === ($hospital->getSize() ?? HospitalSize::MEDIUM)->value,
            ));
            usort($candidates, static fn (Hospital $a, Hospital $b): int => [$a->getTier() !== $tier, (string) $a->getName()] <=> [$b->getTier() !== $tier, (string) $b->getName()]);
            array_push($selected, ...\array_slice($candidates, 0, max(0, $volume->hospitals[$size] ?? 0)));
        }

        $any = max(0, $volume->hospitals[self::ANY] ?? 0);
        if ($any > 0) {
            $chosen = array_flip(array_map(static fn (Hospital $hospital): int => (int) $hospital->getId(), $selected));
            $rest = array_values(array_filter($participating, static fn (Hospital $hospital): bool => !isset($chosen[(int) $hospital->getId()])));
            for ($i = 0; $i < $any && [] !== $rest; ++$i) {
                $index = $this->pick(\count($rest), $volume->seed + $i);
                $selected[] = $rest[$index];
                array_splice($rest, $index, 1);
            }
        }

        return $selected;
    }

    /**
     * The demo user's hospital: one of the active hospitals, drawn with the fixture seed.
     *
     * @param list<Hospital> $active
     */
    public function demoHospital(FixtureVolume $volume, array $active): Hospital
    {
        if ([] === $active) {
            throw new \RuntimeException(sprintf('Fixture profile "%s" selects no participating hospital.', $volume->profile));
        }

        $sorted = $active;
        usort($sorted, static fn (Hospital $a, Hospital $b): int => strcmp((string) $a->getName(), (string) $b->getName()));

        return $sorted[$this->pick(\count($sorted), $volume->seed)];
    }

    /**
     * A repeatable index in [0, count) for the seed, without touching the global random state.
     */
    private function pick(int $count, int $seed): int
    {
        return crc32('fixture-hospital-'.$seed) % $count;
    }
}
