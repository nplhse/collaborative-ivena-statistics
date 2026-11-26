<?php

declare(strict_types=1);

namespace App\Encounter\Application\Grant;

final class EncounterGrantException extends \DomainException
{
    public const string NOT_A_MEMBER = 'not_a_member';

    public const string DUPLICATE = 'duplicate';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Encounter access grant rejected: '.$reason);
    }

    public function translationKey(): string
    {
        return 'encounter.grant.flash.'.$this->reason;
    }
}
