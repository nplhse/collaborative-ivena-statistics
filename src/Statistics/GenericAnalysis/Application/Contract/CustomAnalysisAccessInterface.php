<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Contract;

use App\User\Domain\Entity\User;

interface CustomAnalysisAccessInterface
{
    public function canUseCustomAnalysis(?User $user): bool;
}
