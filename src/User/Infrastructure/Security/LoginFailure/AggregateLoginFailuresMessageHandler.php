<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\LoginFailure;

use App\Shared\Application\JobRun\CurrentJobRunInterface;
use App\User\Application\Message\AggregateLoginFailuresMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily scheduler job `login_failure_aggregation` (ADR 019 job run log).
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class AggregateLoginFailuresMessageHandler
{
    public function __construct(
        private LoginFailureAggregation $aggregation,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(AggregateLoginFailuresMessage $message): void
    {
        $this->jobRun->addMetrics($this->aggregation->run());
    }
}
