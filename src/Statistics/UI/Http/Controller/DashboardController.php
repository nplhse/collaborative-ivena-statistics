<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly HospitalRepository $hospitalRepository,
        private readonly ImportRepository $importRepository,
        private readonly AllocationRepository $allocationRepository,
    ) {
    }

    #[Route('/statistics/', name: 'app_stats_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $charts = $this->buildDashboardCharts();

        return $this->render('@Statistics/dashboard/index.html.twig', [
            'userCount' => $this->userRepository->count(),
            'hospitalCount' => $this->hospitalRepository->count(),
            'participatingHospitalCount' => $this->hospitalRepository->count(['isParticipating' => true]),
            'importCount' => $this->importRepository->count(),
            'allocationCount' => $this->allocationRepository->count(),
            'allocationChart' => $charts['allocationChart'],
            'importChart' => $charts['importChart'],
        ]);
    }

    /**
     * @return array{
     *     allocationChart: array{
     *         labels: string[],
     *         monthlyCounts: int[],
     *         cumulativeCounts: int[]
     *     },
     *     importChart: array{
     *         labels: string[],
     *         monthlyCounts: int[]
     *     }
     * }
     */
    private function buildDashboardCharts(): array
    {
        $currentMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        $start = $currentMonth->modify('-11 months');

        $monthKeys = [];
        $labels = [];

        $cursor = $start;
        while ($cursor <= $currentMonth) {
            $monthKeys[] = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $cursor = $cursor->modify('+1 month');
        }

        $allocationRaw = $this->allocationRepository->countByMonthLast12Months();
        $importRaw = $this->importRepository->countByMonthLast12Months();

        $mapMonthlyCounts = function (array $rawRows, array $keys): array {
            $base = array_fill_keys($keys, 0);

            foreach ($rawRows as $row) {
                $key = sprintf('%04d-%02d', $row['year'], $row['month']);
                if (\array_key_exists($key, $base)) {
                    $base[$key] = (int) $row['count'];
                }
            }

            return array_values($base);
        };

        $allocationMonthlyCounts = $mapMonthlyCounts($allocationRaw, $monthKeys);
        $importMonthlyCounts = $mapMonthlyCounts($importRaw, $monthKeys);
        $initialAllocations = $this->allocationRepository->countBefore($start);

        $allocationCumulativeCounts = [];
        $runningTotal = $initialAllocations;
        foreach ($allocationMonthlyCounts as $value) {
            $runningTotal += $value;
            $allocationCumulativeCounts[] = $runningTotal;
        }

        return [
            'allocationChart' => [
                'labels' => $labels,
                'monthlyCounts' => $allocationMonthlyCounts,
                'cumulativeCounts' => $allocationCumulativeCounts,
            ],
            'importChart' => [
                'labels' => $labels,
                'monthlyCounts' => $importMonthlyCounts,
            ],
        ];
    }
}
