<?php

declare(strict_types=1);

namespace App\Import\Application\Analysis\DTO;

final readonly class RejectAnalysisGroup
{
    public function __construct(
        public int $count,
        public string $field,
        public string $rejectedValue,
        public string $reason,
        public string $exampleFile,
        public string $exampleLine,
        public string $suggestedTransformerHint,
        public string $exampleRawRow = '',
    ) {
    }
}
