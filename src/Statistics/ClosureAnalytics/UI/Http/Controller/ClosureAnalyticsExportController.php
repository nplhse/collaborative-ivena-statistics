<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\Application\RateLimit\ClientRateLimit;
use App\Shared\UI\Http\DataTablePreferenceQueryState;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsCriteriaFactory;
use App\Statistics\ClosureAnalytics\Application\Export\ClosureTableExportBuilder;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureIntervalTableColumns;
use App\Statistics\GenericAnalysis\Application\Contract\AnalysisExportServiceInterface;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(UserRole::PARTICIPANT)]
final class ClosureAnalyticsExportController extends AbstractController
{
    public function __construct(
        private readonly ClosureAnalyticsCriteriaFactory $criteriaFactory,
        private readonly DataTablePreferenceService $dataTablePreferences,
        private readonly ClosureEventTableColumns $eventTableColumns,
        private readonly ClosureIntervalTableColumns $intervalTableColumns,
        private readonly ClosureTableExportBuilder $exportBuilder,
        private readonly AnalysisExportServiceInterface $exportService,
    ) {
    }

    #[Route(
        '/statistics/closure-analytics/export.csv',
        name: 'app_stats_closure_analytics_export_csv',
        methods: ['GET'],
    )]
    public function exportCsv(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
        #[Autowire(service: 'limiter.closure_analytics_export')]
        RateLimiterFactory $closureAnalyticsExportLimiter,
    ): StreamedResponse {
        $userKey = $user instanceof User && null !== $user->getId()
            ? (string) $user->getId()
            : 'anon';
        if (!ClientRateLimit::acceptUserAndIp($closureAnalyticsExportLimiter, 'closure_analytics_export', $userKey, $request)) {
            throw new TooManyRequestsHttpException(message: 'Too many export requests. Please try again later.');
        }

        $criteria = $this->criteriaFactory->create(
            $user,
            $filter,
            ClosureAnalyticsFilterRequestResolver::fromRequest($request),
        );
        $requestedTable = ClosureEventTableState::fromRequest($request);
        $intervalView = 'intervals' === $requestedTable->view;
        $schema = ($intervalView ? $this->intervalTableColumns : $this->eventTableColumns)->preferenceSchema();
        $queryPreferences = DataTablePreferenceQueryState::fromRequest($request);
        $tablePreferences = $this->dataTablePreferences->resolve(
            $user,
            $schema,
            $queryPreferences->visibleColumns,
            $queryPreferences->columnOrder,
            $queryPreferences->pageSize,
        );
        $table = ClosureEventTableState::fromRequest($request, $tablePreferences->pageSize);
        $document = $this->exportBuilder->build($criteria, $table, $tablePreferences);

        return $this->exportService->exportTable(
            $document,
            'csv',
            $this->exportBuilder->filenameTitle($table->view),
        );
    }
}
