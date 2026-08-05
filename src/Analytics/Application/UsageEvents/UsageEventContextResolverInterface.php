<?php

declare(strict_types=1);

namespace App\Analytics\Application\UsageEvents;

use App\User\Domain\Entity\User;

interface UsageEventContextResolverInterface
{
    public function resolveFromRequest(): UsageEventContext;

    public function resolveForUser(User $user): UsageEventContext;
}
