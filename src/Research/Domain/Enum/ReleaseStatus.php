<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * State of a release of an approved data extract request (ADR 045). A release is used once the recipient starts the
 * export; expiry after three months and revocation follow in a later step.
 */
enum ReleaseStatus: string
{
    case Active = 'active';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function labelKey(): string
    {
        return 'data_request.release.status.'.$this->value;
    }
}
