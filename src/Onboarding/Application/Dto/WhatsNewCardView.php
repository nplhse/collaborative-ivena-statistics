<?php

declare(strict_types=1);

namespace App\Onboarding\Application\Dto;

final readonly class WhatsNewCardView
{
    /**
     * @param non-empty-list<WhatsNewItemView> $items
     */
    public function __construct(
        public string $releaseKey,
        public array $items,
    ) {
    }
}
