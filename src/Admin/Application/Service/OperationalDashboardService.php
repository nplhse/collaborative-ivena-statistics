<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\Application\DTO\HealthCheckItemDto;
use App\Admin\Application\DTO\HealthStatusDto;
use App\Admin\Application\DTO\MessengerStatsDto;
use App\Admin\Application\DTO\OpsCardDto;
use App\Admin\Application\DTO\StorageMetricsDto;
use App\Admin\Infrastructure\Repository\MessengerStatsRepository;
use App\Shared\Application\Health\HealthCheckService;
use App\Shared\Infrastructure\Audit\Repository\AuditEntryRepository;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

final readonly class OperationalDashboardService
{
    public function __construct(
        private MessengerStatsRepository $messengerStatsRepository,
        private StorageMetricsService $storageMetricsService,
        private HealthCheckService $healthCheckService,
        private AuditEntryRepository $auditEntryRepository,
        private AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function getMessengerStats(): MessengerStatsDto
    {
        return $this->messengerStatsRepository->getStats();
    }

    public function getHealthStatus(): HealthStatusDto
    {
        $report = $this->healthCheckService->check();

        return new HealthStatusDto(
            status: $report->status,
            appVersion: $report->version,
            checks: $report->checks,
            items: $this->mapHealthCheckItems($report->checks),
        );
    }

    public function getStorageMetrics(): StorageMetricsDto
    {
        return $this->storageMetricsService->getMetrics();
    }

    /**
     * @return list<OpsCardDto>
     */
    public function getOpsCards(): array
    {
        $messenger = $this->getMessengerStats();
        $storage = $this->getStorageMetrics();

        return [
            new OpsCardDto(
                labelKey: 'ops.card.failed_messages',
                value: (string) $messenger->failedCount,
                icon: 'fas fa-triangle-exclamation',
                detailUrl: $this->generateFailedMessagesUrl(),
                style: $messenger->failedCount > 0 ? 'danger' : 'success',
            ),
            new OpsCardDto(
                labelKey: 'ops.card.queue_high',
                value: (string) $messenger->pendingCountFor('high'),
                icon: 'fas fa-bolt',
                style: $messenger->pendingCountFor('high') > 0 ? 'warning' : 'primary',
            ),
            new OpsCardDto(
                labelKey: 'ops.card.queue_low',
                value: (string) $messenger->pendingCountFor('low'),
                icon: 'fas fa-hourglass-half',
                style: $messenger->pendingCountFor('low') > 0 ? 'info' : 'primary',
            ),
            new OpsCardDto(
                labelKey: 'ops.card.storage_total',
                value: $this->formatBytes($storage->totalBytes()),
                icon: 'fas fa-hard-drive',
                trendBytes: $storage->filesBytesLast30Days(),
            ),
            new OpsCardDto(
                labelKey: 'ops.card.database_size',
                value: $this->formatBytes($storage->databaseBytes),
                icon: 'fas fa-database',
                trendBytes: 0,
            ),
            new OpsCardDto(
                labelKey: 'ops.card.imports_media',
                value: $this->formatBytes($storage->filesBytes()),
                icon: 'fa fa-database',
                trendBytes: $storage->filesBytesLast30Days(),
            ),
        ];
    }

    /**
     * @return list<array{occurredAt: \DateTimeImmutable, intent: string, action: string, origin: string}>
     */
    public function getRecentNotificationEvents(int $limit = 10): array
    {
        return $this->auditEntryRepository->findRecentByIntents([
            'hospital.reminder_sent',
            'user.registration',
            'import.failed',
        ], $limit);
    }

    /**
     * @param array<string, string> $checks
     *
     * @return list<HealthCheckItemDto>
     */
    private function mapHealthCheckItems(array $checks): array
    {
        $items = [];

        foreach ($checks as $key => $value) {
            $items[] = match ($key) {
                'database' => new HealthCheckItemDto(
                    key: $key,
                    labelKey: 'ops.health.check.database',
                    value: $value,
                    severity: 'ok' === $value ? 'success' : 'danger',
                    icon: 'fas fa-database',
                ),
                'messenger_failed' => new HealthCheckItemDto(
                    key: $key,
                    labelKey: 'ops.health.check.messenger_failed',
                    value: $value,
                    severity: 'ok' === $value ? 'success' : ('skipped' === $value ? 'warning' : 'warning'),
                    icon: 'ok' === $value ? 'fas fa-envelope-circle-check' : 'fas fa-triangle-exclamation',
                ),
                default => new HealthCheckItemDto(
                    key: $key,
                    labelKey: 'ops.health.check.'.$key,
                    value: $value,
                    severity: 'success',
                    icon: 'fas fa-circle-check',
                ),
            };
        }

        return $items;
    }

    private function generateFailedMessagesUrl(): string
    {
        return $this->adminUrlGenerator
            ->setRoute('app_admin_dashboard_operations_failed_messages')
            ->generateUrl();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return sprintf('%.2f GB', $bytes / (1024 * 1024 * 1024));
    }
}
