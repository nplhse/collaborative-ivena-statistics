<?php

declare(strict_types=1);

namespace App\Notification\UI\Console\Command;

use App\Notification\Application\Reconciliation\NotificationReconciliation;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-publishes lost notifications (ADR 016, "Recovery"). Without options it covers the last 24 hours,
 * like the hourly scheduled run; after a longer outage choose a range with --from and --to.
 */
#[AsCommand(
    name: 'app:notification:reconcile',
    description: 'Re-publish notifications of outcomes from a time range (default: the last 24 hours).',
)]
final readonly class ReconcileNotificationsCommand
{
    public function __construct(
        private NotificationReconciliation $reconciliation,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Start of the range, e.g. "2026-10-20 08:00" (default: 24 hours ago)')]
        ?string $from = null,
        #[Option(description: 'End of the range (default: now)')]
        ?string $to = null,
    ): int {
        $now = $this->clock->now();

        try {
            $end = null === $to ? $now : new \DateTimeImmutable($to);
            $start = null === $from ? $end->sub(new \DateInterval(NotificationReconciliation::WINDOW)) : new \DateTimeImmutable($from);
        } catch (\Exception) {
            $io->error('Could not read --from or --to as a date.');

            return Command::INVALID;
        }

        try {
            $published = $this->reconciliation->reconcile($start, $end);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $resumed = $this->reconciliation->resumePendingDeliveries();

        $io->success(sprintf(
            'Reconciled %s to %s: %d outcome(s) published again (already delivered ones are skipped by deduplication); %d pending delivery job(s) resumed regardless of the range.',
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            $published,
            $resumed,
        ));

        return Command::SUCCESS;
    }
}
