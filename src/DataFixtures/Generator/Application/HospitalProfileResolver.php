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
 * The cath lab and the offered departments are drawn once per hospital from its own stream, so the
 * same seed always gives the same hospitals the same departments: full care offers nearly all,
 * basic care the core of internal medicine and surgery and a few more. The closure frequency
 * follows the size.
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

    /** Departments every hospital offers; they also take the cases of a missing department. */
    public const array CORE_DEPARTMENTS = ['Kardiologie', 'Gastroenterologie', 'Pulmologie', 'Allgemeinchirurgie', 'Unfallchirurgie'];

    /**
     * Chance that a hospital offers a department, by tier (full, extended, basic). Large hospitals
     * add 0.2, small ones subtract 0.2. The order is part of the stream, so new departments go last.
     */
    private const array OPTIONAL_DEPARTMENTS = [
        'Nephrologie' => [1.0, 0.7, 0.3],
        'Infektiologie' => [1.0, 0.5, 0.2],
        'Viszeralchirurgie' => [1.0, 0.8, 0.5],
        'Endoprothetik' => [0.8, 0.6, 0.4],
        'Wirbelsäulenchirurgie' => [0.8, 0.4, 0.1],
        'Neurochirurgie' => [1.0, 0.3, 0.0],
        'Allgemeine Neurologie' => [1.0, 0.8, 0.4],
        'Schlaganfallmedizin' => [1.0, 0.6, 0.2],
        'Neurologische Intensiv' => [1.0, 0.3, 0.0],
        'Epileptologie' => [0.8, 0.2, 0.0],
        'Allgemeine Psychiatrie' => [0.9, 0.5, 0.2],
        'Gerontopsychiatrie' => [0.6, 0.3, 0.1],
        'Suchtmedizin' => [0.7, 0.4, 0.1],
        'Allgemeine Pädiatrie' => [1.0, 0.6, 0.2],
        'Neonatologie' => [1.0, 0.3, 0.0],
        'Kinderkardiologie' => [0.8, 0.1, 0.0],
        'Kindergastroenterologie' => [0.8, 0.1, 0.0],
        'Gynäkologie' => [1.0, 0.8, 0.5],
        'Geburtshilfe' => [1.0, 0.7, 0.4],
        'Pränatalmedizin' => [1.0, 0.3, 0.0],
    ];

    private const array TIER_INDEX = ['Full' => 0, 'Extended' => 1, 'Basic' => 2];

    private const array SIZE_BONUS = ['Large' => 0.2, 'Medium' => 0.0, 'Small' => -0.2];

    /** Average closure events per hospital and 30 days, by size. */
    private const array CLOSURE_EVENTS_PER_MONTH = ['Large' => 8.0, 'Medium' => 5.0, 'Small' => 3.0];

    public function __construct(
        private RandomStreams $streams,
    ) {
    }

    public function resolve(Hospital $hospital, int $seed): HospitalProfile
    {
        $tier = ($hospital->getTier() ?? HospitalTier::BASIC)->value;
        $size = ($hospital->getSize() ?? HospitalSize::MEDIUM)->value;
        $random = $this->streams->stream($seed, $hospital->getPublicIdString(), '-', RandomStreams::PROFILE);

        // The cath lab is drawn first, so adding departments did not change it.
        $hasCathlab = $random->nextFloat() < self::CATHLAB[$tier][$size];
        $missing = [];
        foreach (self::OPTIONAL_DEPARTMENTS as $department => $chances) {
            $chance = $chances[self::TIER_INDEX[$tier]] + self::SIZE_BONUS[$size];
            if ($random->nextFloat() >= $chance) {
                $missing[] = $department;
            }
        }

        return new HospitalProfile(
            segment: $this->segment($hospital),
            hasCathlab: $hasCathlab,
            resusShare: self::RESUS[$tier][$size],
            missingDepartments: $missing,
            closureEventsPerMonth: self::CLOSURE_EVENTS_PER_MONTH[$size],
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
