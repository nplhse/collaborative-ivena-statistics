<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CatalogActionFactory
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<CatalogAction>
     */
    public function forSecondaryTransport(int $id): array
    {
        return [
            new CatalogAction(
                label: $this->translator->trans('catalog.action.view_allocations', [], 'allocation'),
                url: $this->urlGenerator->generate('app_explore_allocation_list', [
                    'secondaryTransport' => $id,
                ]),
                icon: 'tabler:list',
                primary: true,
            ),
        ];
    }

    /**
     * Allocation list filters by indication code; insights routes use the entity id.
     *
     * @return list<CatalogAction>
     */
    public function forIndication(int $id, int $code): array
    {
        return [
            new CatalogAction(
                label: $this->translator->trans('catalog.action.view_allocations', [], 'allocation'),
                url: $this->urlGenerator->generate('app_explore_allocation_list', [
                    'indication' => $code,
                ]),
                icon: 'tabler:list',
                primary: true,
            ),
            new CatalogAction(
                label: $this->translator->trans('catalog.action.indication_insights', [], 'allocation'),
                url: $this->urlGenerator->generate('app_stats_indication_dashboard', [
                    'indicationId' => $id,
                ]),
                icon: 'tabler:chart-bar',
            ),
        ];
    }
}
