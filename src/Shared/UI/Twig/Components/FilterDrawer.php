<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use App\Shared\UI\Twig\DataTable\DataTableQueryState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'FilterDrawer', template: '@Shared/components/FilterDrawer.html.twig')]
final class FilterDrawer
{
    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $id;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $formId;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $formAction;

    public ?string $title = null;

    public ?string $resetUrl = null;

    public bool $showReset = true;

    public string $resetBtnClass = ' btn-outline-secondary';

    public ?string $testId = null;

    public ?string $formTestId = null;

    public ?string $applyTestId = 'filter-drawer-apply';

    public ?string $cancelTestId = 'filter-drawer-cancel';

    public ?string $resetTestId = 'filter-drawer-reset';

    public bool $preserveQuery = false;

    /**
     * @var list<string>
     */
    public array $omitQueryKeys = [];

    /**
     * @var list<string>
     */
    public array $keepQueryKeys = [];

    /** Also keep the DataTable view state (sort, page size, columns) on Apply. */
    public bool $keepViewState = false;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getLabelId(): string
    {
        return $this->id.'-label';
    }

    public function isResetVisible(): bool
    {
        return $this->showReset && null !== $this->resetUrl && '' !== $this->resetUrl;
    }

    /**
     * @return array<string, string>
     */
    public function getRootAttributes(): array
    {
        $attributes = [
            'class' => 'offcanvas offcanvas-end filter-drawer',
            'tabindex' => '-1',
            'id' => $this->id,
            'aria-labelledby' => $this->getLabelId(),
        ];

        if (null !== $this->testId && '' !== $this->testId) {
            $attributes['data-testid'] = $this->testId;
        }

        return $attributes;
    }

    /**
     * Query keys the `query-state` Stimulus controller refreshes from the current URL on Apply.
     * Empty in `preserveQuery` mode, where every key except `omitQueryKeys` is refreshed.
     *
     * @return list<string>
     */
    public function getKeptQueryKeys(): array
    {
        if ($this->preserveQuery) {
            return [];
        }

        return array_values(array_unique($this->keepViewState
            ? [...$this->keepQueryKeys, ...DataTableQueryState::VIEW_KEYS]
            : $this->keepQueryKeys));
    }

    /**
     * @return list<string>
     */
    public function getViewQueryKeys(): array
    {
        return DataTableQueryState::VIEW_KEYS;
    }

    /**
     * Outer query keys copied as hidden inputs on Apply. Pagination keys are never kept.
     *
     * @return array<string, string>
     */
    public function getPreservedQueryFields(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return [];
        }

        /** @var array<array-key, mixed> $query */
        $query = $request->query->all();

        if ($this->preserveQuery) {
            return DataTableQueryState::scalarFields($query, $this->omitQueryKeys);
        }

        $keep = [];
        foreach ($this->getKeptQueryKeys() as $key) {
            if (\array_key_exists($key, $query)) {
                $keep[$key] = $query[$key];
            }
        }

        return DataTableQueryState::scalarFields($keep);
    }
}
