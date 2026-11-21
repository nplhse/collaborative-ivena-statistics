<?php

declare(strict_types=1);

namespace App\Content\Application\MessageHandler;

use App\Content\Application\Contract\ReactionCleanerInterface;
use App\Content\Application\Message\CleanUpReactionsMessage;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Removes reactions of deleted posts, comments and activities (#467). */
#[AsMessageHandler]
final readonly class CleanUpReactionsMessageHandler
{
    public function __construct(
        private ReactionCleanerInterface $cleaner,
        private CurrentJobRunInterface $jobRun,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CleanUpReactionsMessage $message): void
    {
        $deleted = $this->cleaner->removeOrphans();
        $total = array_sum($deleted);

        $this->jobRun->addMetrics(['reactions_deleted' => $total]);
        $this->logger->info('Scheduled reaction cleanup finished.', ['message' => $message::class, 'deleted' => $deleted]);
    }
}
