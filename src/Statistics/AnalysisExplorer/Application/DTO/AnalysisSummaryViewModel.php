<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class AnalysisSummaryViewModel
{
    /**
     * @param list<AnalysisSummaryPart> $parts
     * @param list<string>              $abbreviatedFilterLabels Full "Label: Value" entries for tooltip when filters are abbreviated
     */
    public function __construct(
        public array $parts,
        public array $abbreviatedFilterLabels = [],
    ) {
    }

    public function plainText(): string
    {
        $text = '';
        foreach ($this->parts as $part) {
            $text .= $part->text;
        }

        return $text;
    }

    public function hasParts(): bool
    {
        return [] !== $this->parts;
    }
}
