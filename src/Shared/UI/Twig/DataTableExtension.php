<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\UI\Twig\DataTable\BadgePalette;
use App\Shared\UI\Twig\DataTable\BadgeView;
use App\Shared\UI\Twig\DataTable\DataTableQueryState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DataTableExtension
{
    public function __construct(
        private BadgePalette $badgePalette,
        private RequestStack $requestStack,
    ) {
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'data_table_badge')]
    public function badge(?string $palette, mixed $value): BadgeView
    {
        return $this->badgePalette->resolve($palette, $value);
    }

    /**
     * View state of the current list URL (sort, page size, columns), for filter reset links.
     *
     * @return array<string, string>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'data_table_view_query')]
    public function viewQuery(): array
    {
        return DataTableQueryState::viewFields($this->query());
    }

    /**
     * Current list query without pagination and the omitted keys, for hidden form fields.
     *
     * @param list<string> $omit
     *
     * @return array<string, string>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'data_table_query_fields')]
    public function queryFields(array $omit = []): array
    {
        return DataTableQueryState::scalarFields($this->query(), $omit);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function query(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request instanceof Request ? $request->query->all() : [];
    }
}
