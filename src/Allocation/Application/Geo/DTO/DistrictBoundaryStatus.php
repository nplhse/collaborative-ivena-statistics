<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo\DTO;

use App\Allocation\Domain\Enum\DistrictBoundaryFetchFailure;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchStatus;

/**
 * What an admin sees for a district boundary. A fetch that runs longer than the limit counts as failed, so a stopped
 * worker cannot block a district.
 *
 * @psalm-immutable
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the district boundary field template.
 */
final readonly class DistrictBoundaryStatus
{
    public const int STALE_AFTER_MINUTES = 15;

    public const string READY = 'ready';
    public const string MISSING = 'missing';
    public const string RUNNING = 'running';
    public const string FAILED = 'failed';

    public function __construct(
        public string $state,
        public ?DistrictBoundaryFetchFailure $failure = null,
        public ?string $provider = null,
        public ?string $licence = null,
        public ?string $attribution = null,
        public ?\DateTimeImmutable $fetchedAt = null,
    ) {
    }

    public static function from(
        bool $hasGeometry,
        DistrictBoundaryFetchStatus $fetchStatus,
        ?string $fetchError,
        ?\DateTimeImmutable $requestedAt,
        \DateTimeImmutable $now,
        ?string $provider = null,
        ?string $licence = null,
        ?string $attribution = null,
        ?\DateTimeImmutable $fetchedAt = null,
    ): self {
        if ($hasGeometry) {
            return new self(self::READY, provider: $provider, licence: $licence, attribution: $attribution, fetchedAt: $fetchedAt);
        }

        return match ($fetchStatus) {
            DistrictBoundaryFetchStatus::Idle => new self(self::MISSING),
            DistrictBoundaryFetchStatus::Failed => new self(
                self::FAILED,
                DistrictBoundaryFetchFailure::tryFrom((string) $fetchError) ?? DistrictBoundaryFetchFailure::Unavailable,
            ),
            DistrictBoundaryFetchStatus::Running => !$requestedAt instanceof \DateTimeImmutable || $requestedAt < $now->modify(sprintf('-%d minutes', self::STALE_AFTER_MINUTES))
                ? new self(self::FAILED, DistrictBoundaryFetchFailure::TimedOut)
                : new self(self::RUNNING),
        };
    }

    public function canFetch(): bool
    {
        return self::MISSING === $this->state || self::FAILED === $this->state;
    }
}
