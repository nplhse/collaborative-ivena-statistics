<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

final readonly class AnalysisExplorerLibraryPageViewModel
{
    /**
     * @param list<array{
     *     key: string,
     *     label: string,
     *     cards?: list<array<string, mixed>>,
     *     categories?: list<array{
     *         key: string,
     *         title: string,
     *         label: string,
     *         cards: list<array<string, mixed>>
     *     }>
     * }> $sections
     */
    public function __construct(
        public array $sections,
    ) {
    }
}
