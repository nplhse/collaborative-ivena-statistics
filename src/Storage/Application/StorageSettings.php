<?php

declare(strict_types=1);

namespace App\Storage\Application;

use App\Storage\Application\Capacity\CapacityThresholds;
use App\Storage\Domain\Enum\CapacitySource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Typed storage monitoring configuration (ADR 017): thresholds and maximum measurement age from `app.storage`,
 * budget and capacity reference from STORAGE_BUDGET_BYTES and STORAGE_CAPACITY_SOURCE.
 *
 * @psalm-suppress PossiblyUnusedProperty Not wired yet; the storage health check reads measurementMaxAgeHours.
 */
final readonly class StorageSettings
{
    public CapacityThresholds $thresholds;

    /** Configured budget in bytes; null when none is set. */
    public ?int $budgetBytes;

    /** Which reference to compare usage with; never None, that follows from a missing budget. */
    public CapacitySource $preferredCapacitySource;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        #[Autowire(param: 'app.storage.thresholds.notice_percent')]
        int $noticePercent,
        #[Autowire(param: 'app.storage.thresholds.warning_percent')]
        int $warningPercent,
        #[Autowire(param: 'app.storage.thresholds.critical_percent')]
        int $criticalPercent,
        #[Autowire(param: 'app.storage.budget_bytes')]
        int $budgetBytes,
        #[Autowire(param: 'app.storage.capacity_source')]
        string $capacitySource,
        #[Autowire(param: 'app.storage.measurement_max_age_hours')]
        public int $measurementMaxAgeHours,
    ) {
        $this->thresholds = new CapacityThresholds($noticePercent, $warningPercent, $criticalPercent);
        $this->budgetBytes = $budgetBytes > 0 ? $budgetBytes : null;
        $this->preferredCapacitySource = match (CapacitySource::tryFrom(strtolower(trim($capacitySource)))) {
            CapacitySource::Budget => CapacitySource::Budget,
            CapacitySource::Filesystem => CapacitySource::Filesystem,
            default => throw new \InvalidArgumentException(sprintf('STORAGE_CAPACITY_SOURCE must be "budget" or "filesystem", got "%s".', $capacitySource)),
        };

        if ($measurementMaxAgeHours < 1) {
            throw new \InvalidArgumentException('The maximum storage measurement age must be at least one hour.');
        }
    }
}
