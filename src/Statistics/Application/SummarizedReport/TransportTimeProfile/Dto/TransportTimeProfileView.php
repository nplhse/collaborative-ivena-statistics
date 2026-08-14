<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto;

final readonly class TransportTimeProfileView
{
    /**
     * @param array<string, string>                   $bucketLabels
     * @param array<string, array<string, mixed>>     $chartSpecs
     * @param list<TransportTimeProfileInsight>       $insights
     * @param list<TransportTimeProfileMatrixSection> $matrixSections
     * @param list<TransportTimeProfileMatrixSection> $rankedSections
     */
    public function __construct(
        public bool $hasData,
        public int $allocationCount,
        public int $knownTransportCount,
        public int $unknownTransportCount,
        public string $contextLine,
        public string $scopeLabel,
        public string $periodLabel,
        public string $importCreateUrl,
        public string $dashboardUrl,
        public string $explorerTransportTimeUrl,
        public array $bucketLabels,
        public array $chartSpecs,
        public array $insights,
        public array $matrixSections,
        public array $rankedSections,
        public bool $drawerFilterActive,
    ) {
    }
}
