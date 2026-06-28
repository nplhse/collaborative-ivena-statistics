<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure\Factory;

use App\Onboarding\Domain\Entity\UserOnboardingStep;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<UserOnboardingStep>
 */
final class UserOnboardingStepFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return UserOnboardingStep::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'user' => UserFactory::new(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]),
            'stepKey' => OnboardingStepKey::ViewExploreData,
            'completedAt' => new \DateTimeImmutable(),
        ];
    }
}
