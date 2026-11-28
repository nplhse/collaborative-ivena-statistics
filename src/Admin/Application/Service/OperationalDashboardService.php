<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\Application\DTO\HealthCheckItemLinks;
use App\Admin\Application\DTO\HealthStatusDto;
use App\Admin\Application\DTO\MessengerStatusDto;
use App\Admin\Application\DTO\RecentNotificationEventDto;
use App\Admin\Application\Service\Messenger\MessengerMonitoringService;
use App\Shared\Application\Health\HealthCheckService;
use App\Shared\Application\Health\HealthCheckStatus;
use App\Storage\Application\DTO\StorageSummary;
use App\Storage\Application\View\StorageReportService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

final readonly class OperationalDashboardService
{
    /** The admin job run list; named as a string, so Application does not depend on the UI layer. */
    private const string JOB_RUN_CONTROLLER = \App\Admin\UI\Http\Controller\JobRun\JobRunCrudController::class;

    public function __construct(
        private MessengerMonitoringService $messengerMonitoringService,
        private StorageReportService $storageReportService,
        private HealthCheckService $healthCheckService,
        private RecentNotificationEvents $recentNotificationEvents,
        private AdminUrlGeneratorInterface $adminUrlGenerator,
        private HealthCheckItemMapper $healthCheckItemMapper,
    ) {
    }

    public function getHealthStatus(): HealthStatusDto
    {
        $report = $this->healthCheckService->check();

        return new HealthStatusDto(
            status: $report->status,
            appVersion: $report->version,
            checks: $report->checks,
            items: $this->healthCheckItemMapper->map($report, $this->healthCheckLinks()),
            statuses: $report->statuses,
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
     * The Messenger card: its status is the worst of the Messenger checks in the given health report, so it
     * matches the banner; pending counts and the oldest ready message come from the live queues.
     */
    public function getMessengerStatus(HealthStatusDto $health): MessengerStatusDto
    {
        $status = HealthCheckStatus::Healthy;
        foreach (HealthCheckItemMapper::MESSENGER_CHECKS as $key) {
            $status = $status->worst($health->statuses[$key] ?? HealthCheckStatus::Healthy);
        }

        $queues = $this->messengerMonitoringService->liveQueues();
        $pendingByQueue = [];
        $oldestPendingAt = null;
        foreach (MessengerMonitoringService::TRANSPORTS as $queueName) {
            if (null === $queueName || MessengerMonitoringService::FAILED_QUEUE === $queueName) {
                continue;
            }

            $queue = $queues[$queueName] ?? null;
            $pendingByQueue[$queueName] = $queue['pending'] ?? 0;
            $oldest = $queue['oldestPendingAt'] ?? null;
            if (null !== $oldest && (null === $oldestPendingAt || $oldest < $oldestPendingAt)) {
                $oldestPendingAt = $oldest;
            }
        }

        return new MessengerStatusDto(
            status: $status,
            pendingByQueue: $pendingByQueue,
            failedCount: $queues[MessengerMonitoringService::FAILED_QUEUE]['pending'] ?? 0,
            oldestPendingAt: $oldestPendingAt,
            detailUrl: $this->adminUrlGenerator->setRoute('app_admin_dashboard_operations_messenger')->generateUrl(),
            failedMessagesUrl: $this->generateFailedMessagesUrl(),
        );
    }

    /**
     * @return list<RecentNotificationEventDto>
     */
    public function getRecentNotificationEvents(int $limit = 10): array
    {
        return $this->recentNotificationEvents->latest($limit);
    }

    private function healthCheckLinks(): HealthCheckItemLinks
    {
        return new HealthCheckItemLinks(
            messenger: $this->adminUrlGenerator->setRoute('app_admin_dashboard_operations_messenger')->generateUrl(),
            failedMessages: $this->generateFailedMessagesUrl(),
            media: $this->adminUrlGenerator->setRoute('app_admin_dashboard_media_index')->generateUrl(),
            scheduledJobs: $this->adminUrlGenerator->setController(self::JOB_RUN_CONTROLLER)->setAction('index')->generateUrl(),
        );
    }

    private function generateFailedMessagesUrl(): string
    {
        return $this->adminUrlGenerator
            ->setRoute('app_admin_dashboard_operations_failed_messages')
            ->generateUrl();
    }
}
