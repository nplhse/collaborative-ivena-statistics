<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Status of a pair of ED visit and IVENA allocation (ADR 030).
 *
 * `auto_accepted` and `confirmed` are effective links (one-to-one); `proposed` waits for a decision;
 * `confirmed` and `rejected` are manual decisions that no matcher run changes. A rejected pair is never
 * proposed again.
 */
enum MatchStatus: string
{
    case AutoAccepted = 'auto_accepted';
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    /**
     * @return list<self>
     */
    public static function effective(): array
    {
        return [self::AutoAccepted, self::Confirmed];
    }

    public function isEffective(): bool
    {
        return \in_array($this, self::effective(), true);
    }

    public function isManual(): bool
    {
        return self::Confirmed === $this || self::Rejected === $this;
    }
}
