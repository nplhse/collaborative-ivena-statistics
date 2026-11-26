<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * State of a release of an approved data extract request (ADR 045): used once the recipient starts the export,
 * expired after three months without an export, revoked by an administrator or by the withdrawal of the request.
 */
enum ReleaseStatus: string
{
    case Active = 'active';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Active => 'blue',
            self::Used => 'green',
            self::Expired => 'secondary',
            self::Revoked => 'red',
        };
    }

    public function labelKey(): string
    {
        return 'data_request.release.status.'.$this->value;
    }
}
