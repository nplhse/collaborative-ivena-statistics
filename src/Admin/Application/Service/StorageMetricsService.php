<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\Application\DTO\StorageMetricsDto;
use App\Admin\Application\DTO\StorageSegmentDto;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class StorageMetricsService
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private Connection $connection,
        private ApplicationCodeSizeCalculator $applicationCodeSizeCalculator,
        #[Autowire('%app.admin.storage_limit_bytes%')]
        private int $storageLimitBytes,
    ) {
    }

    public function getMetrics(): StorageMetricsDto
    {
        $importBytes = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(file_size), 0) FROM import WHERE file_size IS NOT NULL',
        );
        $mediaBytes = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(size), 0) FROM media WHERE size IS NOT NULL',
        );
        $databaseBytes = $this->fetchDatabaseSizeBytes();
        $applicationCodeBytes = $this->applicationCodeSizeCalculator->getBytes();

        $since = new \DateTimeImmutable('-30 days', new \DateTimeZone(self::TIMEZONE))
            ->setTime(0, 0)
            ->format('Y-m-d H:i:s');

        $importBytesLast30Days = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(file_size), 0) FROM import WHERE file_size IS NOT NULL AND created_at >= :since',
            ['since' => $since],
        );
        $mediaBytesLast30Days = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(size), 0) FROM media WHERE size IS NOT NULL AND created_at >= :since',
            ['since' => $since],
        );

        $limitBytes = $this->storageLimitBytes > 0 ? $this->storageLimitBytes : null;

        $segments = [
            new StorageSegmentDto('database', 'ops.storage.database', $databaseBytes, '#0d6efd', 'fas fa-database'),
            new StorageSegmentDto('imports', 'ops.storage.imports', $importBytes, '#198754', 'fa fa-database'),
            new StorageSegmentDto('media', 'ops.storage.media', $mediaBytes, '#fd7e14', 'fas fa-photo-film'),
            new StorageSegmentDto('application_code', 'ops.storage.application_code', $applicationCodeBytes, '#6c757d', 'fas fa-code'),
        ];

        return new StorageMetricsDto(
            databaseBytes: $databaseBytes,
            importBytes: $importBytes,
            mediaBytes: $mediaBytes,
            applicationCodeBytes: $applicationCodeBytes,
            importBytesLast30Days: $importBytesLast30Days,
            mediaBytesLast30Days: $mediaBytesLast30Days,
            limitBytes: $limitBytes,
            segments: $segments,
        );
    }

    private function fetchDatabaseSizeBytes(): int
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            return (int) $this->connection->fetchOne('SELECT pg_database_size(current_database())');
        }

        if ($platform instanceof MySQLPlatform) {
            $database = $this->connection->getDatabase();
            if (null === $database) {
                return 0;
            }

            return (int) $this->connection->fetchOne(
                'SELECT SUM(data_length + index_length)
                 FROM information_schema.tables
                 WHERE table_schema = :schema',
                ['schema' => $database],
                ['schema' => ParameterType::STRING],
            );
        }

        if ($platform instanceof SQLitePlatform) {
            $pageCount = (int) $this->connection->fetchOne('PRAGMA page_count');
            $pageSize = (int) $this->connection->fetchOne('PRAGMA page_size');

            return $pageCount * $pageSize;
        }

        return 0;
    }
}
