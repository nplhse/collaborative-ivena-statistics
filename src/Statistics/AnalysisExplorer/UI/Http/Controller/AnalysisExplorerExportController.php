<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\Shared\Application\RateLimit\ClientRateLimit;
use App\Statistics\AnalysisExplorer\Application\AnalysisRunnerRegistry;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisQueryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTableExportBuilder;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Exception\UnsupportedAnalysisException;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\GenericAnalysis\Application\Contract\AnalysisExportServiceInterface;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AnalysisExplorerExportController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'explorer_export_csv';

    public function __construct(
        private readonly ExplorerConfigMapper $configMapper,
        private readonly AnalysisViewConfigNormalizer $configNormalizer,
        private readonly ExplorerAnalysisQueryFactory $queryFactory,
        private readonly AnalysisRunnerRegistry $runnerRegistry,
        private readonly ExplorerResultsTableExportBuilder $exportBuilder,
        private readonly AnalysisExportServiceInterface $exportService,
        private readonly LoggerInterface $logger,
        private readonly UsageAnalytics $usageAnalytics,
    ) {
    }

    #[Route(
        '/statistics/analysis/explorer/export/table.csv',
        name: 'app_stats_analysis_explorer_export_csv',
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    public function exportTableCsv(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
        #[Autowire(service: 'limiter.analysis_explorer_export')]
        RateLimiterFactory $analysisExplorerExportLimiter,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $userKey = $user instanceof User && null !== $user->getId()
            ? (string) $user->getId()
            : 'anon';
        if (!ClientRateLimit::acceptUserAndIp($analysisExplorerExportLimiter, 'analysis_explorer_export', $userKey, $request)) {
            throw new TooManyRequestsHttpException(message: 'Too many export requests. Please try again later.');
        }

        $appliedConfigState = $request->request->getString('appliedConfigState');
        if ('' === $appliedConfigState) {
            throw new BadRequestHttpException('Missing applied configuration state.');
        }

        try {
            /** @var array<string, mixed> $state */
            $state = json_decode($appliedConfigState, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid applied configuration state.');
        }

        $config = $this->configMapper->viewConfigFromState($state, $user);
        $config = $this->applyFilterOverlay($config, $filter);
        $normalizedConfig = $this->configNormalizer->normalize($config);

        try {
            $query = $this->queryFactory->create($normalizedConfig, $user);
            $result = $this->runnerRegistry->run($normalizedConfig, $query);
        } catch (UnsupportedAnalysisException) {
            return new Response('Unsupported analysis configuration.', Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $exception) {
            $this->logger->error('Analysis Explorer CSV export failed.', [
                'exception' => $exception,
            ]);

            return new Response('Analysis export failed.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $document = $this->exportBuilder->build($normalizedConfig, $result);

        $this->usageAnalytics->record(UsageEventName::ANALYSIS_EXPLORER_EXPORTED_CSV, FeatureArea::Export);

        return $this->exportService->exportTable($document, 'csv', $result->title);
    }

    private function applyFilterOverlay(AnalysisViewConfig $config, StatisticsFilter $filter): AnalysisViewConfig
    {
        return new AnalysisViewConfig(
            dataSourceKey: $config->dataSourceKey,
            metricKeys: $config->metricKeys,
            visualMetricKey: $config->visualMetricKey,
            rowAxis: $config->rowAxis,
            columnAxis: $config->columnAxis,
            statisticsFilter: $filter,
            presentation: $config->presentation,
            title: $config->title,
            hospitalPopulationMode: $config->hospitalPopulationMode,
            filters: $config->filters,
        );
    }

    public static function csrfTokenId(): string
    {
        return self::CSRF_TOKEN_ID;
    }
}
