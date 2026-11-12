<?php

declare(strict_types=1);

namespace App\Encounter\Application\Grant;

use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form data for creating or changing an encounter access grant.
 */
final class EncounterGrantInput
{
    #[Assert\NotNull(message: 'validation.encounter.grant.user_required', groups: ['create'])]
    public ?User $user = null;

    /** @var list<EncounterGrantAction> */
    #[Assert\Count(min: 1, minMessage: 'validation.encounter.grant.actions_required')]
    public array $actions = [];

    public static function fromGrant(EncounterAccessGrant $grant): self
    {
        $input = new self();
        $input->user = $grant->getUser();
        $input->actions = $grant->getActions();

        return $input;
    }
}
