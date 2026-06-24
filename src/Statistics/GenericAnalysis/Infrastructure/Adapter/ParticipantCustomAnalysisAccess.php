<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Infrastructure\Adapter;

use App\Statistics\GenericAnalysis\Application\Contract\CustomAnalysisAccessInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CustomAnalysisAccessInterface::class)]
final readonly class ParticipantCustomAnalysisAccess implements CustomAnalysisAccessInterface
{
    #[\Override]
    public function canUseCustomAnalysis(?User $user): bool
    {
        return $user instanceof User
            && \in_array(UserRole::PARTICIPANT, $user->getRoles(), true);
    }
}
