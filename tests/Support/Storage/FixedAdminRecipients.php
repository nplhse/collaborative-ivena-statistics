<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;

final readonly class FixedAdminRecipients implements AdminNotificationRecipientResolverInterface
{
    /**
     * @param list<int> $ids
     */
    public function __construct(
        private array $ids = [7, 9],
    ) {
    }

    #[\Override]
    public function recipientIds(): array
    {
        return $this->ids;
    }
}
