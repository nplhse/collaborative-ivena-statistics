<?php

declare(strict_types=1);

namespace App\Import\Application\Repair;

final readonly class QuoteBrokenImportDiscoveryResult
{
    /**
     * @param list<QuoteBrokenImportCandidate> $ready
     * @param list<QuoteBrokenImportCandidate> $skipped
     */
    public function __construct(
        public array $ready,
        public array $skipped,
    ) {
    }

    /**
     * @return list<int>
     */
    public function readyImportIds(): array
    {
        return array_map(static fn (QuoteBrokenImportCandidate $c): int => $c->importId, $this->ready);
    }
}
