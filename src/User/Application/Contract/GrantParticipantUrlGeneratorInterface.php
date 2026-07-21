<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

interface GrantParticipantUrlGeneratorInterface
{
    public function generate(int $userId): string;
}
