<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Distribution;

use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution/resources', name: 'app_stats_distribution_resources', methods: ['GET'])]
final class ResourcesDistributionController extends AbstractController
{
    public function __construct(
        private readonly DistributionPageConfigResolver $distributionPageConfigResolver,
    ) {
    }

    public function __invoke(): Response
    {
        $options = $this->pageOptions();
        $this->distributionPageConfigResolver->resolve($options);

        return $this->render('@Statistics/distribution/index.html.twig', [
            'distributionPageOptions' => $options,
        ]);
    }

    /**
     * @return array{
     *     route_name: string,
     *     section_key: string,
     *     panels: list<array<string, mixed>>,
     *     default_panel_key?: string|null,
     * }
     */
    private function pageOptions(): array
    {
        $panelBase = [
            'type' => 'distribution',
            'dimension_kind' => DimensionKind::Column->value,
            'group_by_field' => 'hospital_tier_code',
            'group_by_label' => 'statistics.distribution.dim.hospital_tier',
            'filters' => ['date_range', 'hospital_tier', 'hospital_location'],
            'options' => ['default_view' => 'grouped', 'show_percent' => true],
            'controls' => [
                'allow_view_mode_toggle' => true,
                'allow_group_by' => true,
                'allow_bar_basis_average' => true,
            ],
            'average_metric' => 'age',
            'filter_defaults' => [
                'date_range' => 'all_cases',
                'hospital_tier' => [],
                'hospital_location' => [],
            ],
        ];

        return [
            'route_name' => 'app_stats_distribution_resources',
            'section_key' => 'resources',
            'default_panel_key' => 'requires_resus',
            'panels' => [
                array_replace($panelBase, [
                    'key' => 'requires_resus',
                    'dimension_field' => '(CASE WHEN requires_resus IS NULL THEN 0 WHEN requires_resus = false THEN 1 ELSE 2 END)',
                    'dimension_label' => 'statistics.distribution.dim.requires_resus',
                ]),
                array_replace($panelBase, [
                    'key' => 'requires_cathlab',
                    'dimension_field' => '(CASE WHEN requires_cathlab IS NULL THEN 0 WHEN requires_cathlab = false THEN 1 ELSE 2 END)',
                    'dimension_label' => 'statistics.distribution.dim.requires_cathlab',
                ]),
            ],
        ];
    }
}
