<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\ExplorerStatisticsFilterInputFactory;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerAssistantFlowType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsDataQualityReportFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;

final class AnalysisExplorerAssistantController extends AbstractController
{
    public function __construct(
        private readonly AnalysisExplorerAssistantPageViewModelFactory $pageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly ExplorerStatisticsFilterInputFactory $filterInputFactory,
    ) {
    }

    #[Route('/statistics/analysis/assistant/cancel', name: 'app_stats_analysis_assistant_cancel', methods: ['GET'])]
    public function cancel(
        Request $request,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_analysis_assistant_cancel', $publicRedirect['query']);
        }

        $flow = $this->createFlow($request, $filter);
        $draft = $flow->getData();
        $scope = $draft instanceof ExplorerAssistantDraft
            ? $draft->scopePeriod
            : $this->scopePeriodFromFilter($request, $filter);
        $flow->reset();

        return $this->redirectToRoute(
            'app_stats_analysis_library',
            $this->pageViewModelFactory->scopeQueryFromSide($scope),
        );
    }

    #[Route('/statistics/analysis/assistant', name: 'app_stats_analysis_assistant', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_analysis_assistant', $publicRedirect['query']);
        }

        $flow = $this->createFlow($request, $filter);

        if ($request->query->has('new')) {
            $flow->reset();

            return $this->redirectToRoute('app_stats_analysis_assistant', $this->pageViewModelFactory->scopeQuery($request));
        }

        $flow->handleRequest($request);
        if ($request->isMethod('GET')) {
            $stored = $flow->getData();
            if (\is_object($stored) || \is_array($stored)) {
                $flow->getConfig()->getDataStorage()->save($stored);
            }
        }
        if ($flow->isSubmitted() && $flow->isValid() && $flow->isFinished()) {
            $draft = $flow->getData();
            if (!$draft instanceof ExplorerAssistantDraft) {
                throw new \LogicException('The analysis assistant flow must use the assistant draft.');
            }

            return $this->redirectToRoute('app_stats_analysis_explorer', array_merge(
                $this->pageViewModelFactory->scopeQueryFromSide($draft->scopePeriod),
                $draft->toQuery()->openParameters(),
            ));
        }

        $stepForm = $flow->getStepForm();
        $draft = $stepForm->getData();
        if (!$draft instanceof ExplorerAssistantDraft) {
            throw new \LogicException('The analysis assistant flow must use the assistant draft.');
        }

        $formFilter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($draft->scopePeriod),
            $user,
        );
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_analysis_assistant',
            $user,
            $formFilter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_analysis_assistant',
            $formFilter,
        );
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $formFilter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/analysis_explorer_assistant/assistant.html.twig', [
            'dataQualityReport' => $dataQualityReport,
            'statisticsFilter' => $pageViewModel->filter,
            'isLoggedIn' => $pageViewModel->isLoggedIn,
            'statsHideScopeControls' => true,
            'form' => $stepForm,
            'assistantPage' => $this->pageViewModelFactory->create($pageViewModel->filter, $draft),
        ]);
    }

    private function createFlow(Request $request, StatisticsFilter $filter): FormFlowInterface
    {
        $draft = new ExplorerAssistantDraft();
        $draft->scopePeriod = $this->scopePeriodFromFilter($request, $filter);
        $flow = $this->createForm(ExplorerAssistantFlowType::class, $draft, [
            'locale' => $request->getLocale(),
        ]);
        if (!$flow instanceof FormFlowInterface) {
            throw new \LogicException('The analysis assistant form must be a form flow.');
        }

        return $flow;
    }

    private function scopePeriodFromFilter(Request $request, StatisticsFilter $filter): StatisticsScopePeriodFormData
    {
        [$scopeGroup, $scopeDetail] = match ($filter->scope) {
            StatisticsFilterScope::Public => ['public', null],
            StatisticsFilterScope::State => ['state', null !== $filter->stateId ? (string) $filter->stateId : null],
            StatisticsFilterScope::DispatchArea => ['dispatch_area', null !== $filter->dispatchAreaId ? (string) $filter->dispatchAreaId : null],
            StatisticsFilterScope::HospitalCohort => ['hospital_cohort', $filter->cohortType?->value()],
            StatisticsFilterScope::MyHospitals => ['my_hospitals', null],
            StatisticsFilterScope::Hospital => ['my_hospitals', null !== $filter->hospitalId ? (string) $filter->hospitalId : null],
        };
        $now = new \DateTimeImmutable();

        return new StatisticsScopePeriodFormData(
            $scopeGroup,
            $scopeDetail,
            $request->query->has('period') ? $filter->period->value : StatisticsFilterPeriod::AllTime->value,
            $filter->referenceYear ?? (int) $now->format('Y'),
            $filter->referenceQuarter ?? (int) ceil((int) $now->format('n') / 3),
            $filter->referenceMonth ?? (int) $now->format('n'),
        );
    }
}
