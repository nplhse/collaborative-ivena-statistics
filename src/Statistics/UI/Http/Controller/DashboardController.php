<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\ClinicalFeaturesProvider;
use App\Statistics\Application\HospitalSummaryProvider;
use App\Statistics\Application\OverviewDashboardProvider;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DashboardController extends AbstractController
{
    use StatisticsPagePresentationTrait;

    public function __construct(
        private readonly OverviewDashboardProvider $overviewDashboardProvider,
        private readonly HospitalSummaryProvider $hospitalSummaryProvider,
        private readonly ClinicalFeaturesProvider $clinicalFeaturesProvider,
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly HospitalRepository $hospitalRepository,
        private readonly TranslatorInterface $translator,
        private readonly StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    #[\Override]
    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    #[\Override]
    protected function getStatisticsNavigationUrlBuilder(): StatisticsNavigationUrlBuilder
    {
        return $this->statisticsNavigationUrlBuilder;
    }

    #[Route('/statistics/', name: 'app_stats_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $filter = $this->statisticsFilterFactory->createFromRequest(
            $request,
            $user instanceof User ? $user : null,
        );
        $context = new StatisticsContext(
            $user instanceof User ? $user : null,
            $filter,
        );

        $now = new \DateTimeImmutable();
        $defaultYear = $filter->referenceYear ?? (int) $now->format('Y');
        $defaultMonth = $filter->referenceMonth ?? (int) $now->format('n');

        $scopeUrls = [
            'public' => $this->statisticsPageUrl(
                $request,
                'app_stats_dashboard',
                ['scope' => StatisticsFilterScope::Public->value],
                ['hospital'],
            ),
            'my_hospitals' => $this->statisticsPageUrl(
                $request,
                'app_stats_dashboard',
                ['scope' => StatisticsFilterScope::MyHospitals->value],
                ['hospital'],
            ),
        ];

        $accessibleHospitals = [];
        $hospitalUrls = [];
        if ($user instanceof User) {
            $accessibleHospitals = $this->hospitalRepository->findAccessibleHospitalSummaries($user);
            foreach ($accessibleHospitals as $row) {
                $hospitalUrls[(string) $row['id']] = $this->statisticsPageUrl(
                    $request,
                    'app_stats_dashboard',
                    [
                        'scope' => StatisticsFilterScope::Hospital->value,
                        'hospital' => $row['id'],
                    ],
                    [],
                );
            }
        }

        $periodUrls = [
            'all' => $this->statisticsPageUrl(
                $request,
                'app_stats_dashboard',
                ['period' => StatisticsFilterPeriod::All->value],
                ['year', 'month'],
            ),
            'all_time' => $this->statisticsPageUrl(
                $request,
                'app_stats_dashboard',
                ['period' => StatisticsFilterPeriod::AllTime->value],
                ['year', 'month'],
            ),
            'year' => $this->statisticsPageUrl(
                $request,
                'app_stats_dashboard',
                [
                    'period' => StatisticsFilterPeriod::Year->value,
                    'year' => $defaultYear,
                ],
                ['month'],
            ),
            'month' => $this->statisticsPageUrl(
                $request,
                'app_stats_dashboard',
                [
                    'period' => StatisticsFilterPeriod::Month->value,
                    'year' => $defaultYear,
                    'month' => $defaultMonth,
                ],
                [],
            ),
        ];

        $hospitalDropdownSelectedName = null;
        if (StatisticsFilterScope::Hospital === $filter->scope && null !== $filter->hospitalId) {
            foreach ($accessibleHospitals as $row) {
                if ($row['id'] === $filter->hospitalId) {
                    $hospitalDropdownSelectedName = $row['name'];
                    break;
                }
            }
        }

        if ($user instanceof User
            && [] === $accessibleHospitals
            && StatisticsFilterScope::MyHospitals === $filter->scope) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }
        $chartPairWidget = array_find($this->overviewDashboardProvider->build($context), fn ($widget): bool => StatisticWidgetType::ChartPair === $widget->type);

        $locale = $request->getLocale();

        return $this->render('@Statistics/dashboard/index.html.twig', [
            'chartPairWidget' => $chartPairWidget,
            'hospitalSummaryWidgets' => $this->hospitalSummaryProvider->build($context),
            'clinicalFeatureWidgets' => $this->clinicalFeaturesProvider->build($context),
            'statisticsFilter' => $filter,
            'statsScopeUrls' => $scopeUrls,
            'statsHospitalUrls' => $hospitalUrls,
            'statsPeriodUrls' => $periodUrls,
            'accessibleHospitals' => $accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $hospitalDropdownSelectedName,
            'isLoggedIn' => $user instanceof User,
            'statisticsHeadingScope' => $this->statisticsHeadingScope(
                $filter,
                $hospitalDropdownSelectedName,
                $locale,
                $user instanceof User && [] === $accessibleHospitals,
                \count($accessibleHospitals),
            ),
            'statisticsHeadingPeriod' => $this->statisticsHeadingPeriod($filter, $locale),
        ]);
    }
}
