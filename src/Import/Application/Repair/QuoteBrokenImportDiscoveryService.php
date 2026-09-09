<?php

declare(strict_types=1);

namespace App\Import\Application\Repair;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

final readonly class QuoteBrokenImportDiscoveryService
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI. */
    public function __construct(
        private Connection $connection,
        private ImportSourceFileGate $sourceFileGate,
        private LoggerInterface $importLogger,
    ) {
    }

    public function discover(
        ?\DateTimeImmutable $since = null,
        ?int $onlyImportId = null,
    ): QuoteBrokenImportDiscoveryResult {
        $sql = <<<'SQL'
SELECT
    i.id AS import_id,
    i.name AS import_name,
    i.file_path AS file_path,
    h.name AS hospital_name,
    COUNT(r.id) AS reject_count
FROM import_reject r
INNER JOIN import i ON i.id = r.import_id
INNER JOIN hospital h ON h.id = i.hospital_id
WHERE (
    COALESCE(r.row->>'pzc', '') LIKE '332%'
    OR (
        COALESCE(r.row->>'pzc_und_text', '') = ''
        AND (
            r.row->>'manv' ILIKE '%Leitstelle%'
            OR r.row->>'manv' ILIKE '%Disponent%'
        )
    )
    OR (
        CAST(r.messages AS TEXT) ILIKE '%createdAt: not a valid datetime%'
        AND CAST(r.messages AS TEXT) ILIKE '%mciId should not be blank%'
    )
)
SQL;

        $params = [];
        $types = [];

        if ($since instanceof \DateTimeImmutable) {
            $sql .= ' AND i.created_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }

        if (null !== $onlyImportId) {
            $sql .= ' AND i.id = :onlyImportId';
            $params['onlyImportId'] = $onlyImportId;
            $types['onlyImportId'] = ParameterType::INTEGER;
        }

        $sql .= ' GROUP BY i.id, i.name, i.file_path, h.name ORDER BY i.id ASC';

        /** @var list<array{import_id: int|string, import_name: ?string, file_path: ?string, hospital_name: ?string, reject_count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $ready = [];
        $skipped = [];

        foreach ($rows as $row) {
            $filePath = $row['file_path'];
            $status = $this->sourceFileGate->inspect(\is_string($filePath) ? $filePath : null);
            $candidate = new QuoteBrokenImportCandidate(
                importId: (int) $row['import_id'],
                importName: $row['import_name'],
                hospitalName: $row['hospital_name'],
                filePath: \is_string($filePath) ? $filePath : null,
                rejectCount: (int) $row['reject_count'],
                sourceStatus: $status,
            );

            if ($candidate->isReady()) {
                $ready[] = $candidate;
                continue;
            }

            $this->importLogger->warning('import.repair.source_file_skipped', [
                'import_id' => $candidate->importId,
                'file_path' => $candidate->filePath,
                'reason' => $status->value,
            ]);
            $skipped[] = $candidate;
        }

        return new QuoteBrokenImportDiscoveryResult($ready, $skipped);
    }
}
