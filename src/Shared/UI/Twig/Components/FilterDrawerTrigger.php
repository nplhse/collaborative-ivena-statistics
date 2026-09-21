<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'FilterDrawerTrigger', template: '@Shared/components/FilterDrawerTrigger.html.twig')]
final class FilterDrawerTrigger
{
    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $drawerId;

    public int $activeCount = 0;

    public ?string $resetUrl = null;

    public ?string $openTestId = null;

    public ?string $clearTestId = null;

    public ?string $countTestId = null;

    public function shouldShowClearButton(): bool
    {
        return $this->activeCount > 0 && null !== $this->resetUrl && '' !== $this->resetUrl;
    }
}
