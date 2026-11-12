<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\KeyChange\EncounterKeyChangeInProgressException;
use App\Encounter\Domain\Enum\EncounterKeyChangeOption;
use App\User\Domain\Entity\User;

/**
 * Changes the pseudonym key of a hospital (ADR 026). Callers check EncounterAttribute::CHANGE_KEY.
 */
interface EncounterKeyChangerInterface
{
    /**
     * @throws EncounterKeyChangeInProgressException
     */
    public function change(Hospital $hospital, EncounterKeyChangeOption $option, ?User $actor): void;
}
