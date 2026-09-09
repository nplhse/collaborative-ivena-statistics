<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Shared\Application\RateLimit\ClientRateLimit;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\TopList\TopListDefinitionInterface;
use App\Statistics\Application\TopList\TopListDefinitionRegistry;
use App\Statistics\Application\TopList\TopListExportBuilder;
use App\Statistics\Application\TopList\TopListResultLoader;
use App\Statistics\GenericAnalysis\Application\Contract\AnalysisExportServiceInterface;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class TopListsExportController extends AbstractController
{
    public function __construct(
        private readonly TopListsRequestModelFactory $topListsRequestModelFactory,
        private readonly TopListDefinitionRegistry $topListDefinitionRegistry,
        private readonly TopListResultLoader $topListResultLoader,
        private readonly TopListExportBuilder $topListExportBuilder,
        private readonly AnalysisExportServiceInterface $exportService,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
    ) {
    }

    #[Route(
        '/statistics/top-lists/{report}/export.csv',
        name: 'app_stats_top_lists_export_csv',
        requirements: ['report' => '[a-z0-9_]+'],
        methods: ['GET'],
    )]
    public function exportCsv(
        Request $request,
        string $report,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
        #[Autowire(service: 'limiter.top_lists_export')]
        RateLimiterFactory $topListsExportLimiter,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $userKey = $user instanceof User && null !== $user->getId()
            ? (string) $user->getId()
            : 'anon';
        if (!ClientRateLimit::acceptUserAndIp($topListsExportLimiter, 'top_lists_export', $userKey, $request)) {
            throw new TooManyRequestsHttpException(message: 'Too many export requests. Please try again later.');
        }

        $definition = $this->topListDefinitionRegistry->get($report);
        if (!$definition instanceof TopListDefinitionInterface) {
            throw new NotFoundHttpException(sprintf('Unknown top list "%s".', $report));
        }

        $topListsRequest = $this->topListsRequestModelFactory->fromQuery($request->query->all(), $report);
        $resolved = $this->topListResultLoader->load(
            $request,
            $user,
            $filter,
            $definition,
            $topListsRequest->limit,
            $topListsRequest->compare,
        );

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_top_lists_show',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_top_lists_show',
            $filter,
        );

        $scopeB = null;
        $periodB = null;
        if ($topListsRequest->compare) {
            $comparisonPageViewModel = $this->statisticsPageViewModelFactory->create(
                $request,
                'app_stats_top_lists_show',
                $user,
                $resolved->comparisonFilter,
            );
            $comparisonPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
                $request,
                'app_stats_top_lists_show',
                $resolved->comparisonFilter,
            );
            $scopeB = $comparisonPageViewModel->headingScope;
            $periodB = $comparisonPeriodViewModel->headingLabel;
        }

        $document = $this->topListExportBuilder->build(
            $definition,
            $resolved->rankingA,
            $resolved->comparison,
            $pageViewModel->headingScope,
            $overviewPeriodViewModel->headingLabel,
            $scopeB,
            $periodB,
        );

        return $this->exportService->exportTable(
            $document,
            'csv',
            $this->topListExportBuilder->filenameTitle(
                $definition,
                $topListsRequest->limit,
                $topListsRequest->compare,
            ),
        );
    }
}
