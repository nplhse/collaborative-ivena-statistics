<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Insights;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareCriteria;
use App\Statistics\Application\IndicationCompare\IndicationCompareReportService;
use App\Statistics\Application\IndicationCompare\IndicationCompareSubjectRequestParser;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectResolver;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\UI\Http\Controller\IndicationCompareChartPayloadFactory;
use App\Statistics\UI\Http\Controller\IndicationComparePickerViewModelFactory;
use App\Statistics\UI\Http\Controller\IndicationCompareUrlHelper;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InsightsCompareController extends AbstractController
{
    public function __construct(
        private readonly IndicationCompareReportService $reportService,
        private readonly IndicationCompareSubjectRequestParser $subjectRequestParser,
        private readonly IndicationSubjectResolver $subjectResolver,
        private readonly InsightDimensionRegistry $registry,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly InsightsPageChromeFactory $chromeFactory,
        private readonly IndicationComparePickerViewModelFactory $comparePickerViewModelFactory,
        private readonly IndicationCompareChartPayloadFactory $chartPayloadFactory,
        private readonly IndicationCompareUrlHelper $compareUrlHelper,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/statistics/insights/{dimension}/compare',
        name: 'app_stats_insights_compare',
        requirements: ['dimension' => 'indications|indication-groups|specialities|assignments|departments|occasions|infections|secondary-transports'],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(
        Request $request,
        string $dimension,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $dimensionKey = InsightDimensionKey::tryFrom($dimension);
        if (!$dimensionKey instanceof InsightDimensionKey) {
            throw $this->createNotFoundException('Insight dimension not found.');
        }

        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_insights_compare', array_merge(
                $publicRedirect['query'],
                ['dimension' => $dimensionKey->value],
            ));
        }

        $subjects = $this->resolveSubjects($request, $dimensionKey);
        if (null === $subjects) {
            $this->addFlash('error', $this->translator->trans('stats.indication.compare.error.missing_selection', [], 'statistics'));

            return $this->redirectToRoute('app_stats_insights_dimension', array_merge(
                $request->query->all(),
                ['dimension' => $dimensionKey->compareFamily()->value],
            ));
        }

        [$subjectA, $subjectB] = $subjects;

        if ($subjectA->dimension === $subjectB->dimension && $subjectA->id === $subjectB->id) {
            $this->addFlash('error', $this->translator->trans('stats.indication.compare.error.same_indication', [], 'statistics'));

            return $this->redirectToRoute('app_stats_insights_dimension', array_merge(
                $request->query->all(),
                ['dimension' => $dimensionKey->compareFamily()->value],
            ));
        }

        if ($subjectA->population->isEmpty() || $subjectB->population->isEmpty()) {
            $this->addFlash('error', $this->translator->trans('stats.indication.compare.error.empty_group', [], 'statistics'));

            return $this->redirectToRoute('app_stats_insights_dimension', array_merge(
                $request->query->all(),
                ['dimension' => $dimensionKey->compareFamily()->value],
            ));
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        $report = $this->reportService->build(new IndicationCompareCriteria(
            $subjectA,
            $subjectB,
            $scope,
            $period,
        ));

        $overlapIds = [];
        if ($subjectA->population->column === $subjectB->population->column) {
            $overlapIds = array_values(array_intersect($subjectA->population->ids, $subjectB->population->ids));
        }

        return $this->render('@Statistics/insights/compare.html.twig', array_merge(
            $this->chromeFactory->templateVars($request, 'app_stats_insights_compare', $user, $filter),
            [
                'report' => $report,
                'chartPayload' => $this->chartPayloadFactory->create($report),
                'comparePicker' => $this->comparePickerViewModelFactory->create($request, $subjectA, $subjectB),
                'indicationDashboardUrlA' => $this->compareUrlHelper->buildDashboardUrl($request, $subjectA),
                'indicationDashboardUrlB' => $this->compareUrlHelper->buildDashboardUrl($request, $subjectB),
                'hasOverlappingIndications' => [] !== $overlapIds,
                'statsShowCompareEditButton' => true,
                'dimensionKey' => $dimensionKey,
            ],
        ));
    }

    /**
     * @return array{0: InsightSubject, 1: InsightSubject}|null
     */
    private function resolveSubjects(Request $request, InsightDimensionKey $dimension): ?array
    {
        $family = $dimension->compareFamily();
        if (InsightDimensionKey::Indications === $family) {
            $pair = $this->subjectRequestParser->parse($request);
            if (!$pair instanceof \App\Statistics\Application\IndicationCompare\DTO\IndicationCompareSubjectPair) {
                return null;
            }

            $subjectA = $this->subjectResolver->resolve($pair->typeA, $pair->idA);
            $subjectB = $this->subjectResolver->resolve($pair->typeB, $pair->idB);
            if (!$subjectA instanceof \App\Statistics\Application\IndicationDashboard\IndicationSubject || !$subjectB instanceof \App\Statistics\Application\IndicationDashboard\IndicationSubject) {
                return null;
            }

            return [
                $this->subjectResolver->toInsightSubject($subjectA),
                $this->subjectResolver->toInsightSubject($subjectB),
            ];
        }

        $idA = $this->parseId($request->query->get(StatisticsQueryKeys::SUBJECT_A_ID));
        $idB = $this->parseId($request->query->get(StatisticsQueryKeys::SUBJECT_B_ID));
        if (null === $idA || null === $idB) {
            return null;
        }

        $provider = $this->registry->get($family);
        $subjectA = $provider->resolve($idA);
        $subjectB = $provider->resolve($idB);
        if (!$subjectA instanceof InsightSubject || !$subjectB instanceof InsightSubject) {
            return null;
        }

        return [$subjectA, $subjectB];
    }

    private function parseId(mixed $value): ?int
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $stringValue = (string) $value;
        if ('' === $stringValue || !ctype_digit($stringValue)) {
            return null;
        }

        return (int) $stringValue;
    }
}
