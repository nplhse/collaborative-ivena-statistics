<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Retention;

use App\Feedback\Application\Contract\FeedbackQuarantinePurgeInterface;
use App\Feedback\Application\Message\PurgeQuarantinedFeedbackMessage;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily scheduler job `feedback_quarantine_purge` (ADR 019 job run log).
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class PurgeQuarantinedFeedbackMessageHandler
{
    public function __construct(
        private FeedbackQuarantinePurgeInterface $purge,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(PurgeQuarantinedFeedbackMessage $message): void
    {
        $this->jobRun->addMetrics(['deleted' => $this->purge->purge()]);
    }
}
