<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Scheduler;

use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\Message\EvaluateDataRequestDeadlinesMessage;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Scheduler job `research_data_request_deadlines` (ADR 019 job run log): evaluates voting rounds whose deadline
 * passed and lets requested revisions expire that were not submitted in time (ADR 045).
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class EvaluateDataRequestDeadlinesMessageHandler
{
    public function __construct(
        private DataRequestRepository $requests,
        private DataRequestReviewService $review,
        private ClockInterface $clock,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(EvaluateDataRequestDeadlinesMessage $message): void
    {
        $now = $this->clock->now();
        $rounds = 0;
        $expired = 0;
        $skipped = 0;

        foreach ($this->requests->findWithRoundDueBy($now) as $request) {
            try {
                $rounds += $this->review->closeDueRound($request) ? 1 : 0;
            } catch (DataRequestRefused) {
                // Changed in the meantime; the next run picks it up again.
                ++$skipped;
            }
        }

        foreach ($this->requests->findWithRevisionDueBy($now) as $request) {
            try {
                $expired += $this->review->expireRevision($request) ? 1 : 0;
            } catch (DataRequestRefused) {
                ++$skipped;
            }
        }

        if (0 === $rounds + $expired + $skipped) {
            $this->jobRun->markSkipped('nothing_due');

            return;
        }

        $this->jobRun->addMetrics(['rounds_closed' => $rounds, 'revisions_expired' => $expired, 'skipped' => $skipped]);
    }
}
