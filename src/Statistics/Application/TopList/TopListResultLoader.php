<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsDrawerFilterFactory;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class TopListResultLoader
{
    public function __construct(
        private StatisticsContextFactory $statisticsContextFactory,
        private StatisticsDrawerFilterFactory $statisticsDrawerFilterFactory,
        private ComparisonScopeResolver $comparisonScopeResolver,
        private TopListComparisonAssembler $topListComparisonAssembler,
    ) {
    }

    public function load(
        Request $request,
        ?User $user,
        StatisticsFilter $filter,
        TopListDefinitionInterface $definition,
        TopListLimit $limit,
        bool $compare,
    ): TopListResolvedResult {
        $drawerFilter = $this->statisticsDrawerFilterFactory->fromRequest($request);
        $context = $this->statisticsContextFactory->create($user, $filter, drawerFilter: $drawerFilter);
        $rankingA = $definition->fetchRanking($context, $limit->queryLimit());
        $comparisonFilter = $this->comparisonScopeResolver->resolve(
            $request,
            $user,
            $filter,
            HospitalPermission::Statistics,
        );

        $comparison = null;
        if ($compare) {
            $contextB = $this->statisticsContextFactory->create(
                $user,
                $comparisonFilter,
                drawerFilter: $drawerFilter,
            );
            $rankingB = $definition->fetchRanking($contextB, $limit->queryLimit());
            $comparison = $this->topListComparisonAssembler->assemble($rankingA, $rankingB);
        }

        return new TopListResolvedResult($rankingA, $comparisonFilter, $comparison);
    }
}
