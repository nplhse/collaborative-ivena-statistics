<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'FilterDrawer', template: '@Shared/components/FilterDrawer.html.twig')]
final class FilterDrawer
{
    /** @var list<string> */
    public const array DROP_QUERY_KEYS = ['page', 'cursor', 'after', 'before'];

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
            return $this->scalarFields($query, $this->omitQueryKeys);
        }

        $keep = [];
        foreach ($this->keepQueryKeys as $key) {
            if (\array_key_exists($key, $query)) {
                $keep[$key] = $query[$key];
            }
        }

        return $this->scalarFields($keep, []);
    }

    /**
     * @param array<array-key, mixed> $query
     * @param list<string>            $omit
     *
     * @return array<string, string>
     */
    private function scalarFields(array $query, array $omit): array
    {
        $fields = [];
        foreach ($query as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                continue;
            }
            if (\in_array($key, self::DROP_QUERY_KEYS, true) || \in_array($key, $omit, true)) {
                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $string = (string) $value;
            if ('' === $string) {
                continue;
            }
            $fields[$key] = $string;
        }

        return $fields;
    }
}
