<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Contract;

interface GenericAnalysisEntityLabelResolverInterface
{
    public function supports(string $dimensionKey): bool;

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function resolve(string $dimensionKey, array $ids): array;
}
