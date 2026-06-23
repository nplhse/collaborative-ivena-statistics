<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final class ExplorerMetricKeyMapper
{
    public function toRegistryKey(AnalysisMetricKey $key): string
    {
        return $key->registryKey();
    }

    /**
     * @param list<AnalysisMetricKey> $keys
     *
     * @return list<string>
     */
    public function toRegistryKeys(array $keys): array
    {
        return array_map(
            $this->toRegistryKey(...),
            $keys,
        );
    }

    public function toExplorerKey(string $registryKey): ?AnalysisMetricKey
    {
        if ('count' === $registryKey) {
            return AnalysisMetricKey::AllocationCount;
        }

        return AnalysisMetricKey::tryFrom($registryKey);
    }

    /**
     * @param list<string> $registryKeys
     *
     * @return list<AnalysisMetricKey>
     */
    public function toExplorerKeys(array $registryKeys): array
    {
        $keys = [];
        foreach ($registryKeys as $registryKey) {
            $explorerKey = $this->toExplorerKey($registryKey);
            if ($explorerKey instanceof AnalysisMetricKey) {
                $keys[] = $explorerKey;
            }
        }

        return $keys;
    }
}
