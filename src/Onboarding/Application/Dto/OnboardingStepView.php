<?php

declare(strict_types=1);

namespace App\Onboarding\Application\Dto;

use App\Onboarding\Domain\Enum\OnboardingStepKey;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class OnboardingStepView
{
    /**
     * A step without $actionLabel uses the label of its key; $canMarkComplete is false for a step that completes only
     * by itself (#1483).
     */
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
        public ?TranslatableMessage $actionLabel = null,
        public ?string $secondaryActionUrl = null,
        public ?TranslatableMessage $secondaryActionLabel = null,
        public bool $canMarkComplete = true,
        public ?ClinicAccessState $clinicAccess = null,
    ) {
    }
}
