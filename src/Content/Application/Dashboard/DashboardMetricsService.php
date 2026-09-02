<?php

declare(strict_types=1);

namespace App\Content\Application\Dashboard;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Content\Application\Dashboard\DTO\DashboardMetric;
use App\Content\Application\Dashboard\DTO\DashboardMetrics;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Statistics\Infrastructure\Query\GetPlatformAllocationCountsQuery;
use App\Statistics\Infrastructure\Query\PlatformAllocationCounts;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class DashboardMetricsService
{
    private const string TIMEZONE = 'Europe/Berlin';

    private const string CACHE_KEY = 'dashboard.allocation_counts';

    private const int CACHE_TTL_SECONDS = 3600;

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private CacheInterface $cache,
        private GetPlatformAllocationCountsQuery $allocationCountsQuery,
        private HospitalRepository $hospitalRepository,
        private UserRepository $userRepository,
        private ImportRepository $importRepository,
    ) {
    }

    public function get(): DashboardMetrics
    {
        $since = $this->since30Days();
        $allocationCounts = $this->allocationCounts($since);

        return new DashboardMetrics([
            new DashboardMetric(
                key: 'allocations',
                value: $allocationCounts->total,
                deltaLast30Days: $allocationCounts->last30Days,
                icon: 'tabler:ambulance',
                labelTranslationKey: 'dashboard.metrics.allocations',
                routeName: 'app_explore_allocation_list',
            ),
            new DashboardMetric(
                key: 'hospitals',
                value: $this->hospitalRepository->countParticipating(),
                deltaLast30Days: $this->hospitalRepository->countParticipatingSince($since),
                icon: 'tabler:building-hospital',
                labelTranslationKey: 'dashboard.metrics.hospitals',
                routeName: 'app_explore_hospital_list',
                routeParams: ['participating' => '1'],
            ),
            new DashboardMetric(
                key: 'users',
                value: $this->userRepository->count(),
                deltaLast30Days: $this->userRepository->countCreatedSince($since),
                icon: 'tabler:users',
                labelTranslationKey: 'dashboard.metrics.users',
                routeName: 'app_explore_user_list',
            ),
            new DashboardMetric(
                key: 'imports',
                value: $this->importRepository->count(),
                deltaLast30Days: $this->importRepository->countCreatedSince($since),
                icon: 'tabler:database-import',
                labelTranslationKey: 'dashboard.metrics.imports',
                routeName: 'app_import_index',
            ),
        ]);
    }

    private function allocationCounts(\DateTimeImmutable $since): PlatformAllocationCounts
    {
        $cached = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($since): PlatformAllocationCounts {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return ($this->allocationCountsQuery)($since);
        });

        if (!$cached instanceof PlatformAllocationCounts) {
            throw new \LogicException('Cached dashboard allocation counts have an unexpected type.');
        }

        return $cached;
    }

    private function since30Days(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('-30 days', new \DateTimeZone(self::TIMEZONE));
    }
}
