<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

interface NotificationRecipientEmailResolver
{
    /**
     * @return list<string>
     */
    public function resolveRecipientEmails(): array;
}
