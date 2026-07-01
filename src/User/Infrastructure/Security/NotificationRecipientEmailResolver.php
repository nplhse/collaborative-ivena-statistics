<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;

interface NotificationRecipientEmailResolver
{
    /**
     * @return list<User>
     */
    public function resolveRecipientUsers(): array;

    /**
     * @return list<string>
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function resolveRecipientEmails(): array;
}
