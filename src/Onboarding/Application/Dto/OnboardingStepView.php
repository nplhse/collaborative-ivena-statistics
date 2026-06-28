<?php

declare(strict_types=1);

namespace App\Onboarding\Application\Dto;

use App\Onboarding\Domain\Enum\OnboardingStepKey;

final readonly class OnboardingStepView
{
    public function __construct(
        public OnboardingStepKey $key,
        public int $position,
        public string $titleKey,
        public string $descriptionKey,
        public bool $isCompleted,
        public bool $isAutoCompleted,
        public bool $isActionable,
        public ?string $actionUrl,
        public string $actionType,
        public ?string $feedbackMessageKey = null,
    ) {
    }
}
