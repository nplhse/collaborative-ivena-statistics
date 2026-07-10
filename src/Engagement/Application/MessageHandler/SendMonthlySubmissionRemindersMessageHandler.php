<?php

declare(strict_types=1);

namespace App\Engagement\Application\MessageHandler;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\Message\SendMonthlySubmissionRemindersMessage;
use App\Engagement\Application\MonthlyReminderDispatchGuard;
use App\Engagement\Application\MonthlyReminderSender;
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
    ) {
    }

    public function __invoke(SendMonthlySubmissionRemindersMessage $message): void
    {
        $referenceDate = ($message->referenceDate ?? new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone(self::TIMEZONE));

        if (!$this->dispatchGuard->shouldDispatchToday($referenceDate)) {
            $this->logger->info('Monthly submission reminder skipped: not first working day.');

            return;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_KEY);
        if (!$lock->acquire()) {
            $this->logger->info('Monthly submission reminder skipped: another run is in progress.');

            return;
        }

        try {
            $hospitals = $this->hospitalRepository->findParticipatingWithOwner();
            $sent = 0;
            $skipped = 0;
            $bulkIndex = 0;

            foreach ($hospitals as $hospital) {
                $errors = $this->reminderSender->sendForHospital(
                    $hospital,
                    MonthlyReminderTrigger::Scheduler,
                    $referenceDate,
                    $bulkIndex,
                );
                if ([] === $errors) {
                    ++$sent;
                    ++$bulkIndex;
                } else {
                    ++$skipped;
                    $this->logger->info('Monthly submission reminder skipped for hospital.', [
                        'hospital_id' => $hospital->getId(),
                        'errors' => $errors,
                    ]);
                }
            }

            $this->logger->info('Monthly submission reminder batch finished.', [
                'sent' => $sent,
                'skipped' => $skipped,
            ]);
        } finally {
            $lock->release();
        }
    }
}
