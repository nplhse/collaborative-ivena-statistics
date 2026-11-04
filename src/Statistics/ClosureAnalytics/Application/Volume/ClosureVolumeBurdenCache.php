<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Keeps the overview burden per criteria and stratum, since one calculation can take seconds.
 * Every closure rebuild clears it; the lifetime bounds how far an open period lags behind "now".
 */
final readonly class ClosureVolumeBurdenCache
{
    private const int TTL_SECONDS = 86400;

    public function __construct(
        private ClosureVolumeReadModel $readModel,
        #[Autowire(service: 'cache.statistics.closure_volume')]
        private CacheInterface&CacheItemPoolInterface $cache,
    ) {
    }

    public function burden(ClosureAnalyticsCriteria $criteria, ClosureVolumeStratum $stratum): ClosureVolumeBurdenView
    {
        // The criteria hold the permitted hospital scope, so a key never serves another scope.
        $key = 'burden.'.$stratum->value.'.'.hash('xxh128', serialize($criteria));
        $view = $this->cache->get($key, function (ItemInterface $item) use ($criteria, $stratum): ClosureVolumeBurdenView {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->readModel->burden($criteria, $stratum);
        });
        if (!$view instanceof ClosureVolumeBurdenView) {
            throw new \LogicException('Cached closure volume burden has an unexpected type.');
        }

        return $view;
    }

    public function clear(): void
    {
        $this->cache->clear();
    }
}
