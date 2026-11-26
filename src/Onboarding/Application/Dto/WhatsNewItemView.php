<?php

declare(strict_types=1);

namespace App\Onboarding\Application\Dto;

use App\Onboarding\Domain\Enum\WhatsNewItemKey;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class WhatsNewItemView
{
    public function __construct(
        public WhatsNewItemKey $key,
        public TranslatableMessage $title,
        public TranslatableMessage $description,
        public string $actionUrl,
        public TranslatableMessage $actionLabel,
    ) {
    }
}
