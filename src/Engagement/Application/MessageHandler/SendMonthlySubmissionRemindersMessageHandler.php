<?php

declare(strict_types=1);

namespace App\Engagement\Application\MessageHandler;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Engagement\Application\MonthlyReminderDispatchGuard;
use App\Engagement\Application\MonthlyReminderSender;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendMonthlySubmissionRemindersMessageHandler
{
    private const string LOCK_KEY = 'monthly-submission-reminder';
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private HospitalRepository $hospitalRepository,
        private MonthlyReminderSender $reminderSender,
        private MonthlyReminderDispatchGuard $dispatchGuard,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        private CurrentJobRunInterface $jobRun,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SendMonthlySubmissionRemindersMessage $message): void
    {
        $referenceDate = ($message->referenceDate ?? $this->clock->now())
            ->setTimezone(new \DateTimeZone(self::TIMEZONE));

        if (!$this->dispatchGuard->shouldDispatchToday($referenceDate)) {
            $this->jobRun->markSkipped('not_due');
            $this->logger->info('Monthly submission reminder skipped: not first working day.');

            return;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_KEY);
        if (!$lock->acquire()) {
            $this->jobRun->markSkipped('already_running');
            $this->logger->info('Monthly submission reminder skipped: another run is in progress.');

            return;
        }

        try {
            $hospitals = $this->hospitalRepository->findParticipatingWithOwner();
            $sent = 0;
            $skipped = 0;
            /** @var array<string, int> $skipReasons validation error => hospitals */
            $skipReasons = [];

            foreach ($hospitals as $hospital) {
                $errors = $this->reminderSender->sendForHospital(
                    $hospital,
                    MonthlyReminderTrigger::Scheduler,
                    $referenceDate,
                );
                if ([] === $errors) {
                    ++$sent;
                } else {
                    ++$skipped;
                    foreach ($errors as $error) {
                        $skipReasons[$error] = ($skipReasons[$error] ?? 0) + 1;
                    }
                    $this->logger->info('Monthly submission reminder skipped for hospital.', [
                        'hospital_id' => $hospital->getId(),
                        'errors' => $errors,
                    ]);
                }
            }

            $this->jobRun->addMetrics([
                'hospitals' => \count($hospitals),
                // Queued for delivery; the dispatch records whether the email went out.
                'queued' => $sent,
                'skipped' => $skipped,
            ] + $this->skipReasonMetrics($skipReasons));

            $this->logger->info('Monthly submission reminder batch finished.', [
                'sent' => $sent,
                'skipped' => $skipped,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * One counter per skip reason, e.g. skipped_opted_out, named after the sender's validation error.
     *
     * @param array<string, int> $skipReasons
     *
     * @return array<string, int>
     */
    private function skipReasonMetrics(array $skipReasons): array
    {
        $metrics = [];
        foreach ($skipReasons as $error => $count) {
            $metrics['skipped_'.str_replace('monthly_reminder.error.', '', $error)] = $count;
        }
        ksort($metrics);

        return $metrics;
    }
}
