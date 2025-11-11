<?php

declare(strict_types=1);

namespace App\Controller\Statistics;

use App\Entity\Hospital;
use App\Model\Scope;
use App\Service\Statistics\HospitalTimeGridBuilder;
use App\Service\Statistics\Util\Period;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class HospitalTimeGridController extends AbstractController
{
    #[Route('/stats/hospital/{id}', name: 'app_stats_hospital_time_grid')]
    public function index(
        Hospital $hospital,
        Request $request,
        HospitalTimeGridBuilder $gridBuilder,
    ): Response {
        $granularity = strtolower((string) $request->query->get('gran', Period::YEAR));
        $periodKey = (string) $request->query->get('key', '2021-01-01');

        if (!in_array($granularity, Period::allGranularities(), true)) {
            $granularity = Period::YEAR;
        }
        $periodKey = Period::normalizePeriodKey($granularity, $periodKey);

        $scope = new Scope(
            scopeType: 'hospital',
            scopeId: (string) $hospital->getId(),
            granularity: $granularity,
            periodKey: $periodKey
        );

        $metricDefs = [
            ['label' => 'Total',        'key' => 'total',        'format' => 'int'],
            ['label' => 'Male',       'key' => 'genderM',      'format' => 'int'],
            ['label' => 'Female',     'key' => 'genderW',    'format' => 'int'],
            ['label' => 'Urgency 1', 'key' => 'urg1', 'format' => 'int'],
            ['label' => 'Urgency 2', 'key' => 'urg2', 'format' => 'int'],
            ['label' => 'Urgency 3', 'key' => 'urg2', 'format' => 'int'],
            ['label' => 'Resus required', 'key' => 'resusRequired', 'format' => 'int'],
            ['label' => 'Cathlab required', 'key' => 'cathlabRequired', 'format' => 'int'],
            ['label' => 'Is CPR', 'key' => 'isCPR', 'format' => 'int'],
            ['label' => 'Is ventilated', 'key' => 'isVentilated', 'format' => 'int'],
            ['label' => 'Is pregnant', 'key' => 'isPregnant', 'format' => 'int'],
            ['label' => 'Is infectious', 'key' => 'infectious', 'format' => 'int'],
            ['label' => 'With Physician', 'key' => 'withPhysician', 'format' => 'int'],
        ];

        $data = $gridBuilder->build($scope, $metricDefs);

        return $this->render('stats/hospital_time_grid.html.twig', [
            'hospital' => $hospital,
            'scope' => $scope,
            'columns' => $data['columns'],
            'rows' => $data['rows'],
        ]);
    }
}
