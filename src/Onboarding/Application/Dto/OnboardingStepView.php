<?php

declare(strict_types=1);

namespace App\Onboarding\Application\Dto;

use App\Onboarding\Domain\Enum\OnboardingStepKey;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class OnboardingStepView
{
    public function __construct(
        public OnboardingStepKey $key,
        public int $position,
        public TranslatableMessage $title,
        public TranslatableMessage $description,
        public bool $isCompleted,
        public bool $isAutoCompleted,
        public bool $isActionable,
        public ?string $actionUrl,
        public string $actionType,
        public ?TranslatableMessage $feedbackMessage = null,
    ) {
    }
}
