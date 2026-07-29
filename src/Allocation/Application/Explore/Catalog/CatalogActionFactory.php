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
        return [$this->viewAllocationsAction('secondaryTransport', $id)];
    }

    /**
     * Allocation list filters by indication code; insights routes use the entity id.
     *
     * @return list<CatalogAction>
     */
    public function forIndication(int $id, int $code, bool $includeReviewWorklist = false): array
    {
        $actions = [
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

        if ($includeReviewWorklist) {
            $actions[] = new CatalogAction(
                label: $this->translator->trans('catalog.action.review_raw_indications', [], 'allocation'),
                url: $this->urlGenerator->generate('app_explore_indication_raw_review_worklist'),
                icon: 'tabler:checklist',
            );
        }

        return $actions;
    }

    /**
     * @return list<CatalogAction>
     */
    public function forDepartment(int $id): array
    {
        return [$this->viewAllocationsAction('department', $id)];
    }

    /**
     * @return list<CatalogAction>
     */
    public function forSpeciality(int $id): array
    {
        return [$this->viewAllocationsAction('speciality', $id)];
    }

    /**
     * @return list<CatalogAction>
     */
    public function forAssignment(int $id): array
    {
        return [$this->viewAllocationsAction('assignment', $id)];
    }

    /**
     * @return list<CatalogAction>
     */
    public function forOccasion(int $id): array
    {
        return [$this->viewAllocationsAction('occasion', $id)];
    }

    /**
     * @return list<CatalogAction>
     */
    public function forInfection(int $id): array
    {
        return [$this->viewAllocationsAction('infection', $id)];
    }

    /**
     * @return list<CatalogAction>
     */
    public function forState(int $id): array
    {
        return [
            $this->viewAllocationsAction('state', $id),
            new CatalogAction(
                label: $this->translator->trans('catalog.action.view_hospitals', [], 'allocation'),
                url: $this->urlGenerator->generate('app_explore_hospital_list', [
                    'state' => $id,
                ]),
                icon: 'tabler:building-hospital',
            ),
            new CatalogAction(
                label: $this->translator->trans('catalog.action.view_dispatch_areas', [], 'allocation'),
                url: $this->urlGenerator->generate('app_explore_dispatch_area_list', [
                    'state' => $id,
                ]),
                icon: 'tabler:map',
            ),
        ];
    }

    /**
     * @return list<CatalogAction>
     */
    public function forDispatchArea(int $id): array
    {
        return [$this->viewAllocationsAction('dispatchArea', $id)];
    }

    /**
     * Indication groups have no allocation-list filter; link to the statistics dashboard.
     *
     * @return list<CatalogAction>
     */
    public function forIndicationGroup(int $id): array
    {
        return [
            new CatalogAction(
                label: $this->translator->trans('catalog.action.indication_group_insights', [], 'allocation'),
                url: $this->urlGenerator->generate('app_stats_indication_group_dashboard', [
                    'groupId' => $id,
                ]),
                icon: 'tabler:chart-bar',
                primary: true,
            ),
        ];
    }

    private function viewAllocationsAction(string $filterParam, int $id): CatalogAction
    {
        return new CatalogAction(
            label: $this->translator->trans('catalog.action.view_allocations', [], 'allocation'),
            url: $this->urlGenerator->generate('app_explore_allocation_list', [
                $filterParam => $id,
            ]),
            icon: 'tabler:list',
            primary: true,
        );
    }
}
