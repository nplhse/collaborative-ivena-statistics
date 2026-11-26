<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Access;

/**
 * Access to case data for pages of encounter schema plugins (ADR 037), so that a plugin needs neither the
 * Hospital entity nor the Encounter voter. Same rule as EncounterAttribute::VIEW_CASES: beta role and the
 * grant action VIEW_CASES (or REVIEW) for the hospital, or ROLE_ADMIN.
 */
interface EncounterAccessInterface
{
    /**
     * Whether the current user may view case data of the hospital; false for an unknown hospital.
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function canViewCases(int $hospitalId): bool;

    /**
     * Hospitals with encounter data whose cases the current user may view, by name.
     *
     * @return list<array{id: int, name: string}>
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function hospitalsWithViewCases(): array;
}
