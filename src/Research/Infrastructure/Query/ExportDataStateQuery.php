<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Query;

use App\Research\Application\Export\ExportDataStateInterface;
use Doctrine\DBAL\Connection;

/**
 * The imports an export is based on (ADR 045): finished imports, that is completed or partly completed ones, at the
 * time of the run. Read with the application connection; the import table is not part of the export allowlist.
 *
 * @psalm-suppress UnusedClass Autowired through its interface.
 */
final readonly class ExportDataStateQuery implements ExportDataStateInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function current(): array
    {
        /** @var array{latest_import_at: ?string, imports: int|string, latest_import_id: int|string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            "SELECT max(created_at) AS latest_import_at, count(*) AS imports, max(id) AS latest_import_id FROM import WHERE status IN ('Completed', 'Partial')",
        );

        return [
            'latest_import_at' => false === $row || null === $row['latest_import_at'] ? null : new \DateTimeImmutable($row['latest_import_at'])->format(\DATE_ATOM),
            'imports' => false === $row ? 0 : (int) $row['imports'],
            'latest_import_id' => false === $row || null === $row['latest_import_id'] ? null : (int) $row['latest_import_id'],
        ];
    }
}
