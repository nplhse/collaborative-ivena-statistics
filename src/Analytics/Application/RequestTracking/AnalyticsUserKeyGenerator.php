<?php

declare(strict_types=1);

namespace App\Analytics\Application\RequestTracking;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AnalyticsUserKeyGenerator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $appSecret,
    ) {
    }

    public function generate(int $userId): string
    {
        return hash_hmac('sha256', (string) $userId, $this->appSecret);
    }
}
