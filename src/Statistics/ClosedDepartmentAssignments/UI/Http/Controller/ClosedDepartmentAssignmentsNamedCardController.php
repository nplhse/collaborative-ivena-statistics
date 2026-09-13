<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsCriteriaFactory;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsService;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentNamedCard;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentAssignmentsCriteria;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\ClosedDepartmentMetricsQuery;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ClosedDepartmentAssignmentsNamedCardController extends AbstractController
{
    public function __construct(
        private readonly ClosedDepartmentAssignmentsService $dashboardService,
        private readonly ClosedDepartmentAssignmentsCriteriaFactory $criteriaFactory,
        private readonly ClosedDepartmentMetricsQuery $metricsQuery,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    #[Route(
        '/statistics/closed-department-assignments/cards/{card}',
        name: 'app_stats_closed_department_assignments_card',
        methods: ['GET'],
    )]
    public function frame(
        Request $request,
        ClosedDepartmentNamedCard $card,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $criteria = $this->criteriaFactory->create($user, $filter, $this->isGranted('ROLE_PARTICIPANT'));
        $closedCount = $this->closedCount($request, $criteria);
        $topListReport = $card->topListReport();

        return $this->render('@Statistics/closed_department_assignments/_named_card_frame.html.twig', [
            'frameId' => $card->frameId(),
            'titleKey' => $card->titleKey(),
            'testId' => $card->testId(),
            'rows' => $this->dashboardService->buildNamedCard($criteria, $card, $closedCount),
            'topListUrl' => null === $topListReport
                ? null
                : $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_top_lists_show',
                    ['report' => $topListReport, 'departmentWasClosed' => 1],
                    ['closedCount', 'card'],
                ),
        ]);
    }

    private function closedCount(
        Request $request,
        ClosedDepartmentAssignmentsCriteria $criteria,
    ): int {
        if ($request->query->has('closedCount')) {
            return max(0, $request->query->getInt('closedCount'));
        }

        return $this->metricsQuery->fetchKpis(
            $criteria->period->from,
            $criteria->period->toExclusive,
            $criteria->scope,
        )->closedCount;
    }
}
