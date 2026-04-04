<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Distribution;

use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use App\Statistics\Application\Panel\Distribution\TransportTimeBucketExpression;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution/transport-time', name: 'app_stats_distribution_transport_time', methods: ['GET'])]
final class TransportTimeDistributionController extends AbstractController
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
     * }
     */
    private function pageOptions(): array
    {
        return [
            'route_name' => 'app_stats_distribution_transport_time',
            'section_key' => 'transport_time',
            'panels' => [
                [
                    'key' => 'transport_time_bucket',
                    'type' => 'distribution',
                    'dimension_kind' => DimensionKind::Column->value,
                    'dimension_field' => TransportTimeBucketExpression::sql('transport_time_minutes'),
                    'dimension_label' => 'statistics.distribution.dim.transport_time_bucket',
                    'group_by_field' => 'hospital_tier_code',
                    'group_by_label' => 'statistics.distribution.dim.hospital_tier',
                    'filters' => ['date_range', 'hospital_tier', 'hospital_location'],
                    'options' => ['default_view' => 'grouped', 'show_percent' => true],
                    'controls' => [
                        'allow_view_mode_toggle' => true,
                        'allow_group_by' => true,
                        'allow_bar_basis_average' => true,
                    ],
                    'average_metric' => 'transport_time_minutes',
                    'filter_defaults' => [
                        'date_range' => 'all_cases',
                        'hospital_tier' => [],
                        'hospital_location' => [],
                    ],
                ],
            ],
        ];
    }
}
