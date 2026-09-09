<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\UI\Http\Controller;

use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationAllocationsResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationBedsResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationCoverageResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationParticipationResult;
use App\Statistics\HospitalPopulation\Application\HospitalPopulationDashboardService;
use App\Statistics\HospitalPopulation\Application\HospitalPopulationSection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HospitalPopulationController extends AbstractController
{
    public function __construct(
        private readonly HospitalPopulationDashboardService $dashboardService,
        private readonly HospitalPopulationChartPayloadFactory $chartPayloadFactory,
        private readonly HospitalPopulationMapPayloadFactory $mapPayloadFactory,
    ) {
    }

    #[Route(
        '/statistics/hospital-population/characteristics',
        name: 'app_stats_hospital_population_legacy_characteristics',
        methods: ['GET'],
    )]
    public function redirectLegacyCharacteristics(): RedirectResponse
    {
        return $this->redirectToRoute('app_stats_hospital_population', [
            'section' => HospitalPopulationSection::Beds->value,
        ]);
    }

    #[Route(
        '/statistics/hospital-population/{section}',
        name: 'app_stats_hospital_population',
        requirements: ['section' => 'participation|coverage|beds|allocations'],
        defaults: ['section' => 'participation'],
        methods: ['GET'],
    )]
    public function __invoke(HospitalPopulationSection $section): Response
    {
        return match ($section) {
            HospitalPopulationSection::Participation => $this->renderParticipation($section),
            HospitalPopulationSection::Coverage => $this->renderCoverage($section),
            HospitalPopulationSection::Beds => $this->renderBeds($section),
            HospitalPopulationSection::Allocations => $this->renderAllocations($section),
        };
    }

    private function renderParticipation(HospitalPopulationSection $section): Response
    {
        $result = $this->dashboardService->buildParticipation();

        return $this->renderSection($section, $result, mapPayload: $this->mapPayloadFactory->create($result));
    }

    private function renderCoverage(HospitalPopulationSection $section): Response
    {
        return $this->renderSection($section, $this->dashboardService->buildCoverage());
    }

    private function renderBeds(HospitalPopulationSection $section): Response
    {
        $result = $this->dashboardService->buildBeds();

        return $this->renderSection($section, $result, chartPayload: $this->chartPayloadFactory->createBeds($result));
    }

    private function renderAllocations(HospitalPopulationSection $section): Response
    {
        $result = $this->dashboardService->buildAllocations();

        return $this->renderSection($section, $result, chartPayload: $this->chartPayloadFactory->createAllocations($result));
    }

    /**
     * @param array<string, mixed> $chartPayload
     * @param array<string, mixed> $mapPayload
     */
    private function renderSection(
        HospitalPopulationSection $section,
        HospitalPopulationParticipationResult|HospitalPopulationCoverageResult|HospitalPopulationBedsResult|HospitalPopulationAllocationsResult $result,
        array $chartPayload = [],
        array $mapPayload = [],
    ): Response {
        return $this->render('@Statistics/hospital_population/index.html.twig', [
            'section' => $section,
            'result' => $result,
            'chartPayload' => $chartPayload,
            'mapPayload' => $mapPayload,
            'tabs' => $this->tabs($section),
        ]);
    }

    /**
     * @return list<array{key: string, labelKey: string, url: string, active: bool}>
     */
    private function tabs(HospitalPopulationSection $activeSection): array
    {
        $tabs = [];

        foreach (HospitalPopulationSection::cases() as $section) {
            $tabs[] = [
                'key' => $section->value,
                'labelKey' => $section->labelKey(),
                'url' => $this->generateUrl('app_stats_hospital_population', ['section' => $section->value]),
                'active' => $section === $activeSection,
            ];
        }

        return $tabs;
    }
}
