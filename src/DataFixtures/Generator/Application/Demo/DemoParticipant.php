<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Demo;

/**
 * The participant account the demo wizard created. The password exists only here and is shown once.
 */
final readonly class DemoParticipant
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $email,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }
}
