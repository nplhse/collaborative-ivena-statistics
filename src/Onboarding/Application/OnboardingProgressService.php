<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Application\Dto\OnboardingCardView;
use App\Onboarding\Domain\Entity\UserOnboardingStep;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Onboarding\Infrastructure\Repository\UserOnboardingStepRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class OnboardingProgressService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private OnboardingStepCatalog $stepCatalog,
        private UserOnboardingStepRepository $stepRepository,
    ) {
    }

    public function buildCardForUser(User $user): ?OnboardingCardView
    {
        if (!\in_array(UserRole::PARTICIPANT, $user->getRoles(), true)) {
            return null;
        }

        $persisted = $this->persistedCompletedMap($user);
        $allSteps = $this->stepCatalog->buildStepsForUser($user, $persisted);

        if ([] === $allSteps) {
            return null;
        }

        $openSteps = [];
        $completedSteps = [];

        foreach ($allSteps as $step) {
            if ($step->isCompleted) {
                $completedSteps[] = $step;
            } else {
                $openSteps[] = $step;
            }
        }

        if ([] === $openSteps) {
            return null;
        }

        $totalCount = \count($allSteps);
        $completedCount = \count($completedSteps);
        $progressPercent = (int) round($completedCount * 100 / $totalCount);

        return new OnboardingCardView(
            openSteps: $openSteps,
            completedSteps: $completedSteps,
            completedCount: $completedCount,
            totalCount: $totalCount,
            progressPercent: $progressPercent,
        );
    }

    public function markCompleted(User $user, OnboardingStepKey $stepKey): void
    {
        $persisted = $this->persistedCompletedMap($user);
        if (!$this->stepCatalog->isStepAvailableForUser($user, $stepKey, $persisted)) {
            throw new AccessDeniedHttpException('Onboarding step is not available for this user.');
        }

        if ($this->stepCatalog->isAutoCompletedForUser($user, $stepKey)) {
            return;
        }

        if ($this->stepRepository->findForUserAndStep($user, $stepKey) instanceof UserOnboardingStep) {
            return;
        }

        $this->stepRepository->save(new UserOnboardingStep($user, $stepKey));
    }

    /**
     * @return array<string, bool>
     */
    private function persistedCompletedMap(User $user): array
    {
        $map = [];
        foreach (array_keys($this->stepRepository->findCompletedByUserIndexed($user)) as $key) {
            $map[$key] = true;
        }

        return $map;
    }
}
