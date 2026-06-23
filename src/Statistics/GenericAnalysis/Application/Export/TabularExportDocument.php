<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Export;

final readonly class TabularExportDocument
{
    /**
     * @param list<TabularExportColumn>                  $headers
     * @param iterable<int, list<string|int|float|null>> $rows
     * @param list<list<string|int|float|null>>          $footerRows
     */
    public function __construct(
        public array $headers,
        public iterable $rows,
        public array $footerRows = [],
    ) {
    }
}
