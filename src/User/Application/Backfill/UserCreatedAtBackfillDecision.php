<?php

declare(strict_types=1);

namespace App\User\Application\Backfill;

/**
 * Which historical date `app:user:backfill-created-at` takes for one user: the earliest successful own import first,
 * otherwise the earliest successful import of a hospital the user owns (ties go to the lower hospital id). The date
 * replaces createdAt only when it is earlier.
 */
final readonly class UserCreatedAtBackfillDecision
{
    /**
     * @param 'own'|'hospital'|null $source
     */
    private function __construct(
        public ?string $source,
        public ?\DateTimeImmutable $candidate,
        public ?string $hospitalName,
        public bool $shouldUpdate,
    ) {
    }

    /**
     * @param list<array{id: int, name: string}> $ownedHospitals
     * @param array<int, \DateTimeImmutable>     $firstImportByHospital hospitalId => earliest successful import
     */
    public static function decide(
        \DateTimeImmutable $currentCreatedAt,
        ?\DateTimeImmutable $firstOwnImport,
        array $ownedHospitals,
        array $firstImportByHospital,
    ): self {
        if ($firstOwnImport instanceof \DateTimeImmutable) {
            return new self('own', $firstOwnImport, null, $firstOwnImport < $currentCreatedAt);
        }

        $hospitalCandidate = self::earliestHospitalImport($ownedHospitals, $firstImportByHospital);
        if (null !== $hospitalCandidate) {
            return new self(
                'hospital',
                $hospitalCandidate['createdAt'],
                $hospitalCandidate['name'],
                $hospitalCandidate['createdAt'] < $currentCreatedAt,
            );
        }

        return new self(null, null, null, false);
    }

    /**
     * @param list<array{id: int, name: string}> $hospitals
     * @param array<int, \DateTimeImmutable>     $firstImportByHospital
     *
     * @return array{createdAt: \DateTimeImmutable, name: string}|null
     */
    private static function earliestHospitalImport(array $hospitals, array $firstImportByHospital): ?array
    {
        $bestAt = null;
        $bestName = null;
        $bestHospitalId = null;

        foreach ($hospitals as $hospital) {
            $createdAt = $firstImportByHospital[$hospital['id']] ?? null;
            if (!$createdAt instanceof \DateTimeImmutable) {
                continue;
            }

            if (
                !$bestAt instanceof \DateTimeImmutable
                || $createdAt < $bestAt
                || ($createdAt == $bestAt && $hospital['id'] < (int) $bestHospitalId)
            ) {
                $bestAt = $createdAt;
                $bestName = $hospital['name'];
                $bestHospitalId = $hospital['id'];
            }
        }

        if (!$bestAt instanceof \DateTimeImmutable || null === $bestName) {
            return null;
        }

        return [
            'createdAt' => $bestAt,
            'name' => $bestName,
        ];
    }
}
