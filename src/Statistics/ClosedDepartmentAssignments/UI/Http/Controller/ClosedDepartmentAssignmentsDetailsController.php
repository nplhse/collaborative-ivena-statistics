<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsCriteriaFactory;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsService;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ClosedDepartmentAssignmentsDetailsController extends AbstractController
{
    public function __construct(
        private readonly ClosedDepartmentAssignmentsService $dashboardService,
        private readonly ClosedDepartmentAssignmentsCriteriaFactory $criteriaFactory,
        private readonly ClosedDepartmentChartPayloadFactory $chartPayloadFactory,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    #[Route('/statistics/closed-department-assignments/details', name: 'app_stats_closed_department_assignments_details', methods: ['GET'])]
    public function frame(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $criteria = $this->criteriaFactory->create($user, $filter, $this->isGranted('ROLE_PARTICIPANT'));
        $result = $this->dashboardService->buildDetails($criteria);

        return $this->render('@Statistics/closed_department_assignments/_details_frame.html.twig', [
            'dashboard' => $result,
            'chartPayload' => $this->chartPayloadFactory->createTransport($result->transport),
            'statisticsFilter' => $filter,
            'isochroneOriginMapUrl' => $this->navigationUrlBuilder->build(
                $request,
                'app_stats_isochrone_origin_map',
                ['departmentWasClosed' => 1],
            ),
        ]);
    }
}
