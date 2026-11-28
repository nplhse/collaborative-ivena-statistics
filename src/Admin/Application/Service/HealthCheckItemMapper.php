<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\Application\DTO\HealthCheckItemDto;
use App\Admin\Application\DTO\HealthCheckItemLinks;
use App\Shared\Application\Health\HealthCheckReport;
use App\Shared\Application\Health\HealthCheckStatus;

/**
 * Health items of the admin dashboard card (ADR 019). The Messenger checks collapse into one "Messenger: ok" item
 * while all of them are ok; otherwise only the ones that are not ok are shown, each with its own severity.
 */
final readonly class HealthCheckItemMapper
{
    /** Checks shown as one "Messenger" item on the dashboard while all of them are ok. */
    public const array MESSENGER_CHECKS = ['messenger_failed', 'messenger_backlog', 'messenger_stalled', 'messenger_retries'];

    public function __construct(
        private StorageHealthItemFactory $storageHealthItemFactory,
    ) {
    }

    /**
     * @return list<HealthCheckItemDto>
     */
    public function map(HealthCheckReport $report, HealthCheckItemLinks $links): array
    {
        $messengerKeys = array_values(array_intersect(self::MESSENGER_CHECKS, array_keys($report->checks)));
        $messengerOk = [] !== $messengerKeys && [] === array_filter(
            $messengerKeys,
            static fn (string $key): bool => 'ok' !== $report->checks[$key],
        );

        $items = [];
        $messengerShown = false;
        foreach ($report->checks as $key => $value) {
            if (\in_array($key, self::MESSENGER_CHECKS, true)) {
                if ($messengerOk && !$messengerShown) {
                    $items[] = $this->statusItem('messenger', 'ok', HealthCheckStatus::Healthy, 'fas fa-tower-broadcast', $links->messenger);
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
                    detailUrl: 'skipped' === $value ? null : $links->failedMessages,
                ),
                'storage' => $this->storageHealthItemFactory->create($value),
                'messenger_backlog', 'messenger_stalled', 'messenger_retries' => $this->statusItem($key, $value, $report->statusOf($key), 'fas fa-tower-broadcast', $links->messenger),
                'media' => $this->statusItem($key, $value, $report->statusOf($key), 'fas fa-photo-film', $links->media),
                // Two-factor key (#1357, ADR 040): no page of its own; the value names the problem.
                'two_factor' => $this->statusItem($key, $value, $report->statusOf($key), 'fas fa-key', null),
                // Export connection of data extract requests (#1635, ADR 045): no page of its own; the value names the state.
                'research_export' => $this->statusItem($key, $value, $report->statusOf($key), 'fas fa-file-export', null),
                // Maintenance mode (#1680, ADR 046): `on` is degraded, so a mode left on after a deploy stands out.
                'maintenance' => $this->statusItem($key, $value, $report->statusOf($key), 'fas fa-screwdriver-wrench', null),
                'scheduled_jobs' => $this->statusItem($key, $value, $report->statusOf($key), 'fas fa-stopwatch', $links->scheduledJobs),
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
     * A check whose severity follows its health status (ADR 019), linking to the page that explains it, if there is one.
     */
    private function statusItem(string $key, string $value, HealthCheckStatus $status, string $icon, ?string $detailUrl): HealthCheckItemDto
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
}
