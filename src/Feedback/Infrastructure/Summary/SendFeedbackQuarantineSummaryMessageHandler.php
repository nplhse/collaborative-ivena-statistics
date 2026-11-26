<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Summary;

use App\Feedback\Application\Contract\FeedbackQuarantineSummaryInterface;
use App\Feedback\Application\Message\SendFeedbackQuarantineSummaryMessage;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Weekly scheduler job `feedback_quarantine_summary` (ADR 019 job run log).
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class SendFeedbackQuarantineSummaryMessageHandler
{
    public function __construct(
        private FeedbackQuarantineSummaryInterface $summary,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(SendFeedbackQuarantineSummaryMessage $message): void
    {
        $reported = $this->summary->send();
        if (0 === $reported) {
            $this->jobRun->markSkipped('nothing_to_report');

            return;
        }

        $this->jobRun->addMetrics(['reported' => $reported]);
    }
}
