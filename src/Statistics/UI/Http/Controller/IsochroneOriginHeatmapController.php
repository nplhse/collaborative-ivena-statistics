<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationDashboard\IndicationSubject;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectResolver;
use App\Statistics\Application\IsochroneOriginMap\IsochroneOriginHeatmapAssembler;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class IsochroneOriginHeatmapController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly IsochroneOriginHeatmapAssembler $assembler,
        private readonly IndicationSubjectResolver $subjectResolver,
    ) {
    }

    #[Route('/statistics/widgets/isochrone-origin-map', name: 'app_stats_isochrone_origin_map', methods: ['GET'])]
    public function frame(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        $heatmap = $this->assembler->build(
            $filter,
            $scope,
            $period,
            $this->resolveIndicationIds($request),
        );

        return $this->render('@Statistics/isochrone_origin_map/_frame.html.twig', [
            'heatmap' => $heatmap,
        ]);
    }

    /**
     * @return list<int>|null
     */
    private function resolveIndicationIds(Request $request): ?array
    {
        $indicationId = $this->positiveIntQuery($request, 'indicationId');
        if (null !== $indicationId) {
            $subject = $this->subjectResolver->resolveSingle($indicationId);
            if (!$subject instanceof IndicationSubject) {
                return [];
            }

            return $subject->indicationIds;
        }

        $groupId = $this->positiveIntQuery($request, 'groupId');
        if (null !== $groupId) {
            $subject = $this->subjectResolver->resolveGroup($groupId);
            if (!$subject instanceof IndicationSubject) {
                return [];
            }

            return $subject->indicationIds;
        }

        return null;
    }

    private function positiveIntQuery(Request $request, string $key): ?int
    {
        $raw = $request->query->get($key);
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
