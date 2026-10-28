<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Notification;

use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunNotifierInterface;
use App\DataFixtures\Generator\Application\Run\GenerationRunView;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;

/**
 * One `generation.completed` per completed hospital of a finished run with `notify`, to the hospital's owner
 * (the participant). Failed hospitals get none. The publisher checks hospital access as for every
 * hospital-scoped type; the deduplication key per run and hospital lets the reconciler call this again.
 */
final readonly class GenerationRunNotifier implements GenerationRunNotifierInterface
{
    public function __construct(private NotificationPublisherInterface $publisher)
    {
    }

    #[\Override]
    public function notify(GenerationRunView $run): int
    {
        if (!$run->notify || !$run->status->isTerminal()) {
            return 0;
        }

        $published = 0;
        foreach ($run->hospitals as $hospital) {
            if (GenerationRunHospitalStatus::Completed !== $hospital->status || null === $hospital->ownerId) {
                continue;
            }

            $this->publisher->publish(new NotificationRequest(
                type: 'generation.completed',
                recipientUserIds: [$hospital->ownerId],
                deduplicationKey: \sprintf('generation.completed:%s:%d', $run->id, $hospital->hospitalId),
                parameters: [
                    'hospitalName' => $hospital->hospitalName,
                    'months' => $hospital->months,
                    'allocations' => $hospital->allocations,
                    'closures' => $hospital->closures,
                ],
                action: new NotificationAction('app_default'),
                scopeReferenceId: $hospital->hospitalId,
                metadata: ['reference' => \sprintf('generation_run:%s', $run->id)],
            ));
            ++$published;
        }

        return $published;
    }
}
