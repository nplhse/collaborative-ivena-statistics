<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;

interface FeedbackRecipientEmailResolver
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
