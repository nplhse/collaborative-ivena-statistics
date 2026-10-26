<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\Application\DTO\HealthCheckItemDto;
use App\Admin\Application\DTO\HealthStatusDto;
use App\Admin\Application\DTO\MessengerStatsDto;
use App\Admin\Application\DTO\OpsCardDto;
use App\Admin\Infrastructure\Repository\MessengerStatsRepository;
use App\Shared\Application\Health\HealthCheckReport;
use App\Shared\Application\Health\HealthCheckService;
use App\Shared\Application\Health\HealthCheckStatus;
use App\Shared\Infrastructure\Audit\Repository\AuditEntryRepository;
use App\Storage\Application\DTO\StorageSummary;
use App\Storage\Application\View\StorageReportService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

final readonly class OperationalDashboardService
{
    /** The admin job run list; named as a string, so Application does not depend on the UI layer. */
    /** Checks shown as one "Messenger" item on the dashboard while all of them are ok (ADR 019). */
    private const array MESSENGER_CHECKS = ['messenger_failed', 'messenger_backlog', 'messenger_stalled', 'messenger_retries'];

    private const string JOB_RUN_CONTROLLER = \App\Admin\UI\Http\Controller\JobRun\JobRunCrudController::class;

    public function __construct(
        private MessengerStatsRepository $messengerStatsRepository,
        private StorageReportService $storageReportService,
        private HealthCheckService $healthCheckService,
        private AuditEntryRepository $auditEntryRepository,
        private AdminUrlGeneratorInterface $adminUrlGenerator,
        private StorageHealthItemFactory $storageHealthItemFactory,
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
            items: $this->mapHealthCheckItems($report),
        );
    }

    /**
     * The latest persisted storage measurement for the compact dashboard card; null before the first one.
     */
    public function getStorageSummary(): ?StorageSummary
    {
        return $this->storageReportService->summary();
    }

    /**
     * @return list<OpsCardDto>
     */
    public function getOpsCards(): array
    {
        $messenger = $this->getMessengerStats();

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
     * Health items for the dashboard card. The Messenger checks collapse into one "Messenger: ok" item while all
     * of them are ok; otherwise only the ones that are not ok are shown, each with its own severity.
     *
     * @return list<HealthCheckItemDto>
     */
    private function mapHealthCheckItems(HealthCheckReport $report): array
    {
        $messengerKeys = array_values(array_intersect(self::MESSENGER_CHECKS, array_keys($report->checks)));
        $messengerOk = [] !== $messengerKeys && [] === array_filter(
            $messengerKeys,
            static fn (string $key): bool => 'ok' !== $report->checks[$key],
        );
        $messengerUrl = $this->adminUrlGenerator->setRoute('app_admin_dashboard_operations_messenger')->generateUrl();

        $items = [];
        $messengerShown = false;
        foreach ($report->checks as $key => $value) {
            if (\in_array($key, self::MESSENGER_CHECKS, true)) {
                if ($messengerOk && !$messengerShown) {
                    $items[] = $this->statusItem('messenger', 'ok', HealthCheckStatus::Healthy, 'fas fa-tower-broadcast', $messengerUrl);
                    $messengerShown = true;
                }
                if ($messengerOk || 'ok' === $value) {
                    continue;
                }
            }

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
                    severity: 'warning',
                    icon: 'fas fa-triangle-exclamation',
                    detailUrl: 'skipped' === $value ? null : $this->generateFailedMessagesUrl(),
                ),
                'storage' => $this->storageHealthItemFactory->create($value),
                'messenger_backlog', 'messenger_stalled', 'messenger_retries' => $this->statusItem($key, $value, $report->statusOf($key), 'fas fa-tower-broadcast', $messengerUrl),
                'scheduled_jobs' => $this->statusItem($key, $value, $report->statusOf($key), 'fas fa-stopwatch', $this->adminUrlGenerator->setController(self::JOB_RUN_CONTROLLER)->setAction('index')->generateUrl()),
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

    /**
     * A check whose severity follows its health status (ADR 019), linking to the page that explains it.
     */
    private function statusItem(string $key, string $value, HealthCheckStatus $status, string $icon, string $detailUrl): HealthCheckItemDto
    {
        return new HealthCheckItemDto(
            key: $key,
            labelKey: 'ops.health.check.'.$key,
            value: $value,
            severity: match ($status) {
                HealthCheckStatus::Healthy => 'success',
                HealthCheckStatus::Degraded => 'warning',
                HealthCheckStatus::Unhealthy => 'danger',
            },
            icon: HealthCheckStatus::Healthy === $status ? $icon : 'fas fa-triangle-exclamation',
            detailUrl: $detailUrl,
        );
    }

    private function generateFailedMessagesUrl(): string
    {
        return $this->adminUrlGenerator
            ->setRoute('app_admin_dashboard_operations_failed_messages')
            ->generateUrl();
    }
}
