<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use App\Shared\UI\Twig\DataTable\DataTableQueryState;
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

    /** The clear link keeps the current DataTable view state (sort, page size, columns). */
    public bool $keepViewState = false;

    /**
     * @return list<string>
     */
    public function getViewQueryKeys(): array
    {
        return DataTableQueryState::VIEW_KEYS;
    }

    public function shouldShowClearButton(): bool
    {
        return $this->activeCount > 0 && null !== $this->resetUrl && '' !== $this->resetUrl;
    }
}
