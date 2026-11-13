<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

use App\Allocation\Application\Contracts\HospitalDataSnapshotReaderInterface;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps one snapshot per hospital. The data only changes with an import, so entries are cleared and warmed when an
 * import or its projection changes; the lifetime is a safety net. Missing entries are read together in one call.
 */
final readonly class CachedHospitalDataSnapshotReader
{
    private const int TTL_SECONDS = 86400;

    private const string KEY_PREFIX = 'hospital_data_snapshot.v1.';

    public function __construct(
        private HospitalDataSnapshotReaderInterface $reader,
        #[Autowire(service: 'cache.allocation.hospital_data_status')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, HospitalDataSnapshot> keyed by hospital id, in the requested order
     */
    public function read(array $hospitalIds): array
    {
        $hospitalIds = array_values(array_unique($hospitalIds));
        if ([] === $hospitalIds) {
            return [];
        }

        $cached = [];
        $missing = [];
        foreach ($this->cache->getItems(array_map($this->key(...), $hospitalIds)) as $item) {
            $value = $item->isHit() ? $item->get() : null;
            if ($value instanceof HospitalDataSnapshot) {
                $cached[$value->hospitalId] = $value;
            }
        }
        foreach ($hospitalIds as $hospitalId) {
            if (!isset($cached[$hospitalId])) {
                $missing[] = $hospitalId;
            }
        }

        if ([] !== $missing) {
            $cached += $this->store($missing);
        }

        $result = [];
        foreach ($hospitalIds as $hospitalId) {
            if (isset($cached[$hospitalId])) {
                $result[$hospitalId] = $cached[$hospitalId];
            }
        }

        return $result;
    }

    /**
     * Reads the hospitals again and overwrites their entries, whether they were cached or not.
     *
     * @param list<int> $hospitalIds
     */
    public function refresh(array $hospitalIds): void
    {
        $hospitalIds = array_values(array_unique($hospitalIds));
        if ([] === $hospitalIds) {
            return;
        }

        $stored = $this->store($hospitalIds);
        // A hospital the reader no longer knows must not keep an old entry.
        $this->forget(array_values(array_diff($hospitalIds, array_keys($stored))));
    }

    /**
     * @param list<int> $hospitalIds
     */
    public function forget(array $hospitalIds): void
    {
        if ([] !== $hospitalIds) {
            $this->cache->deleteItems(array_map($this->key(...), $hospitalIds));
        }
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, HospitalDataSnapshot>
     */
    private function store(array $hospitalIds): array
    {
        $snapshots = $this->reader->read($hospitalIds);
        foreach ($snapshots as $hospitalId => $snapshot) {
            $item = $this->cache->getItem($this->key($hospitalId));
            $item->set($snapshot);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->cache->saveDeferred($item);
        }
        $this->cache->commit();

        return $snapshots;
    }

    private function key(int $hospitalId): string
    {
        return self::KEY_PREFIX.$hospitalId;
    }
}
