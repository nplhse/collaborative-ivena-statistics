<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;

/**
 * Derives the profile of a hospital from its care tier, size and location.
 *
 * The cath lab is drawn once per hospital from its own stream, so the same seed always gives the
 * same hospitals a cath lab: every full-care hospital, most large extended-care hospitals and
 * only a few basic-care hospitals.
 */
final readonly class HospitalProfileResolver
{
    /** Chance of a cath lab by tier and size. */
    private const array CATHLAB = [
        'Full' => ['Large' => 1.0, 'Medium' => 1.0, 'Small' => 1.0],
        'Extended' => ['Large' => 0.8, 'Medium' => 0.5, 'Small' => 0.3],
        'Basic' => ['Large' => 0.15, 'Medium' => 0.1, 'Small' => 0.05],
    ];

    /** Share of allocations that need the resuscitation room, by tier and size. */
    private const array RESUS = [
        'Full' => ['Large' => 0.10, 'Medium' => 0.10, 'Small' => 0.08],
        'Extended' => ['Large' => 0.06, 'Medium' => 0.05, 'Small' => 0.04],
        'Basic' => ['Large' => 0.03, 'Medium' => 0.02, 'Small' => 0.01],
    ];

    public function __construct(
        private RandomStreams $streams,
    ) {
    }

    public function resolve(Hospital $hospital, int $seed): HospitalProfile
    {
        $tier = ($hospital->getTier() ?? HospitalTier::BASIC)->value;
        $size = ($hospital->getSize() ?? HospitalSize::MEDIUM)->value;
        $random = $this->streams->stream($seed, $hospital->getPublicIdString(), '-', RandomStreams::PROFILE);

        return new HospitalProfile(
            segment: $this->segment($hospital),
            hasCathlab: $random->nextFloat() < self::CATHLAB[$tier][$size],
            resusShare: self::RESUS[$tier][$size],
        );
    }

    private function segment(Hospital $hospital): string
    {
        return match (true) {
            HospitalTier::FULL === $hospital->getTier() => 'urban-full',
            HospitalLocation::RURAL === $hospital->getLocation() => 'rural-basic',
            default => 'urban-basic',
        };
    }
}
