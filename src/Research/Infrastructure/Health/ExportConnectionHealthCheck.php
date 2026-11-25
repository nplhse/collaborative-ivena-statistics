<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Health;

use App\Research\Infrastructure\Export\ExportConnection;
use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Export connection of data extract requests in Application Health (ADR 045), one query. Without
 * RESEARCH_DATABASE_URL exports are simply off (`disabled`, healthy). Degraded when the connection fails
 * (`unreachable`) or its role could change data (`writable`): the export would then not run as the hosting's read-only
 * user. Not public. RESEARCH_EXPECT_READ_ONLY=0 accepts a writable connection, for the test environment, which shares
 * the application user.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class ExportConnectionHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'research_export';

    public function __construct(
        private ExportConnection $exportConnection,
        /** Only an explicit 0 or false switches the expectation off; a missing value keeps it. */
        #[Autowire(env: 'default::RESEARCH_EXPECT_READ_ONLY')]
        private ?string $expectReadOnly = null,
    ) {
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $state = $this->exportConnection->state();
        $healthy = [ExportConnection::DISABLED, ExportConnection::READ_ONLY];
        if (\in_array($this->expectReadOnly, ['0', 'false'], true)) {
            $healthy[] = ExportConnection::WRITABLE;
        }

        $status = \in_array($state, $healthy, true) ? HealthCheckStatus::Healthy : HealthCheckStatus::Degraded;

        return new HealthCheckResult(self::KEY, $status, $state);
    }
}
