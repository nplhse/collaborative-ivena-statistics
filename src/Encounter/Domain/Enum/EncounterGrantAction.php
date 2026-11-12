<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Actions of an encounter access grant (ADR 027). REVIEW implies VIEW_CASES.
 * None of them allows the key change, which is reserved for the hospital owner and administrators.
 */
enum EncounterGrantAction: string
{
    case Upload = 'upload';
    case Review = 'review';
    case ViewCases = 'view_cases';

    public function labelKey(): string
    {
        return 'encounter.grant.action.'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'encounter.grant.action.'.$this->value.'_help';
    }
}
