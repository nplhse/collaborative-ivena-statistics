<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay\Controller;

use App\Encounter\Contract\Access\EncounterAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsQueryKeys;
use App\Statistics\UI\Http\Controller\AnalysisContextScopeMode;
use App\Statistics\UI\Http\Controller\AnalysisContextViewModelFactory;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Tests\Fixtures\Plugin\InpatientStay\Entity\InpatientStay;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A plugin's statistics page through the Statistics page API (#1300): statistics filter and period, the analysis
 * context with the hospitals the user may view, and the header controls of the statistics pages. It counts the
 * stays admitted in the period at one hospital.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class InpatientStayStatisticsController extends AbstractController
{
    public const string ROUTE = 'app_stats_inpatient_stays';

    public function __construct(
        private readonly EncounterAccessInterface $access,
        private readonly AnalysisContextViewModelFactory $analysisContextFactory,
        private readonly OverviewPeriodViewModelFactory $periodViewModelFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/statistics/inpatient-stays', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $hospitals = array_column($this->access->hospitalsWithViewCases(), 'name', 'id');
        if ([] === $hospitals) {
            throw $this->createAccessDeniedException();
        }

        $requested = $request->query->getInt(StatisticsQueryKeys::HOSPITAL);
        $hospitalId = \array_key_exists($requested, $hospitals) ? $requested : (int) array_key_first($hospitals);
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $period = $this->periodViewModelFactory->create($request, self::ROUTE, $filter);

        return $this->render('@InpatientStayTest/statistics.html.twig', [
            'stays' => $this->countStays($hospitalId, $bounds->from, $bounds->toExclusive),
            'statsAnalysisContext' => $this->analysisContextFactory->create(
                $request,
                self::ROUTE,
                $user,
                $filter,
                $hospitals[$hospitalId],
                $period->headingLabel,
                scopeMode: AnalysisContextScopeMode::AssignedHospital,
                assignedHospitals: $hospitals,
            ),
        ]);
    }

    private function countStays(int $hospitalId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(InpatientStay::class, 's')
            ->where('s.hospitalId = :hospital')
            ->setParameter('hospital', $hospitalId);
        if ($from instanceof \DateTimeImmutable) {
            $query->andWhere('s.admittedAt >= :from')->setParameter('from', $from);
        }

        if ($toExclusive instanceof \DateTimeImmutable) {
            $query->andWhere('s.admittedAt < :to')->setParameter('to', $toExclusive);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }
}
