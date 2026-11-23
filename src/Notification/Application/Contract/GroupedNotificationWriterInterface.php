<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\Notification\Application\Publishing\NotificationRequest;

/**
 * Cross-context port for notifications that summarise several events in one entry (ADR 016, "Grouped notifications").
 *
 * The producer decides what belongs to a group and recomputes it; this port only stores the result. Grouped types are
 * in-app only. Storage errors are thrown, so that the producer's transaction rolls back and its job is retried.
 */
interface GroupedNotificationWriterInterface
{
    /**
     * Creates the entry of a new group for the single recipient of the request. Its deduplication key is not claimed:
     * a withdrawn group must be able to come back.
     *
     * @return int|null the entry's id, null when the recipient switched this type off
     *
     * @throws \InvalidArgumentException when the type offers another channel or the request has several recipients
     *
     * @psalm-suppress PossiblyUnusedMethod Port for producing contexts; the first caller arrives with #973.
     */
    public function create(NotificationRequest $request): ?int;

    /**
     * Updates the text of an entry that is still unread, without raising the unread count.
     *
     * @param array<string, scalar|null> $parameters translation parameters
     * @param \DateTimeImmutable|null    $moveTo     new position in the inbox; null keeps the current one
     *
     * @return bool false when the entry is gone, read or removed: the group is closed
     *
     * @psalm-suppress PossiblyUnusedMethod Port for producing contexts; the first caller arrives with #973.
     */
    public function replace(int $notificationId, array $parameters, ?\DateTimeImmutable $moveTo = null): bool;

    /**
     * Removes an entry that is still unread, because nothing is left to report.
     *
     * @return bool false when the entry is gone, read or removed
     *
     * @psalm-suppress PossiblyUnusedMethod Port for producing contexts; the first caller arrives with #973.
     */
    public function withdraw(int $notificationId): bool;
}
