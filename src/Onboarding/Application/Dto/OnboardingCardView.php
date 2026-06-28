<?php

declare(strict_types=1);

namespace App\Onboarding\Application\Dto;

final readonly class OnboardingCardView
{
    /**
     * @param list<OnboardingStepView> $openSteps
     * @param list<OnboardingStepView> $completedSteps
     */
    public function __construct(
        public array $openSteps,
        public array $completedSteps,
        public int $completedCount,
        public int $totalCount,
        public int $progressPercent,
    ) {
    }
}
