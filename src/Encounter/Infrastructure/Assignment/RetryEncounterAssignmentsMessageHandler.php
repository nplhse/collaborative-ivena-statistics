<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Encounter\Application\Assignment\AssignmentRetryResult;
use App\Encounter\Application\Contract\AssignmentRetrierInterface;
use App\Encounter\Application\Message\RetryEncounterAssignmentMessage;
use App\Encounter\Application\Message\RetryEncounterAssignmentsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the regular retry of a hospital and the targeted retry of one record (#1307) and logs their counts
 * (no record contents) in the import channel.
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
final readonly class RetryEncounterAssignmentsMessageHandler
{
    public function __construct(
        private AssignmentRetrierInterface $retrier,
        #[Target('import')]
        private LoggerInterface $importLogger,
    ) {
    }

    #[AsMessageHandler]
    public function retryHospital(RetryEncounterAssignmentsMessage $message): void
    {
        $this->log('encounter.assignment.retried', $message->hospitalId, null, $this->retrier->retryHospital($message->hospitalId));
    }

    #[AsMessageHandler]
    public function retryOne(RetryEncounterAssignmentMessage $message): void
    {
        $this->log('encounter.assignment.retried_one', $message->hospitalId, $message->pendingId, $this->retrier->retryOne($message->hospitalId, $message->pendingId));
    }

    private function log(string $event, int $hospitalId, ?int $pendingId, AssignmentRetryResult $result): void
    {
        $this->importLogger->info($event, [
            'hospital_id' => $hospitalId,
            'pending_id' => $pendingId,
            'attached' => $result->attached,
            'reopened' => $result->reopened,
            'unchanged' => $result->unchanged,
            'skipped' => $result->skipped,
        ]);
    }
}
