<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * Who sees an entry of a data extract request's timeline (ADR 045). Administrators always see every level.
 *
 * - Internal: Board members only (votes, their reasons, exclusions).
 * - Private: Board members and the applicant (for example the reason for a requested revision).
 * - Public: everyone who may see the request.
 */
enum Visibility: string
{
    case Internal = 'internal';
    case Private = 'private';
    case Public = 'public';

    /** How many readers the level reaches; a reply may not reach more readers than the entry it answers. */
    public function reach(): int
    {
        return match ($this) {
            self::Internal => 0,
            self::Private => 1,
            self::Public => 2,
        };
    }

    public function isNarrowerThan(self $other): bool
    {
        return $this->reach() < $other->reach();
    }

    public function labelKey(): string
    {
        return 'data_request.visibility.'.$this->value;
    }
}
