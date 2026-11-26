<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Contract;

use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogStatus;

/**
 * Raw rows of the MTS catalog tables (ADR 032) for MtsCatalog, which applies the resolution rule.
 */
interface MtsCatalogReaderInterface
{
    /**
     * All entries of one kind, merged ones included, ordered by code.
     *
     * @return list<array{code: string, german_name: string, english_name: ?string, status: MtsCatalogStatus}>
     */
    public function entries(MtsCatalogKind $kind): array;

    /**
     * @return array<string, string> alias code => target code
     */
    public function aliases(MtsCatalogKind $kind): array;

    /**
     * Diagram–indicator assignments (definitions, ADR 032), ordered by diagram code and indicator code.
     *
     * @return list<array{diagram: string, indicator: string}>
     */
    public function assignments(): array;
}
