<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

final readonly class ImportRequeueBatchOptions
{
    public function __construct(
        public bool $dryRun = false,
        public int $fromId = 1,
        public ?int $limit = null,
        public ?int $onlyId = null,
        public bool $resume = false,
        public ?int $runId = null,
        public int $maxRetriesPerImport = 3,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'fromId' => $this->fromId,
            'limit' => $this->limit,
            'onlyId' => $this->onlyId,
            'resume' => $this->resume,
            'runId' => $this->runId,
            'maxRetriesPerImport' => $this->maxRetriesPerImport,
        ];
    }
}
