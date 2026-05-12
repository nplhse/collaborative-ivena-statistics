<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\CaseId;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CaseIdHasher
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function normalize(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return '' === $digits ? null : $digits;
    }

    public function hashFrom(?string $raw): ?string
    {
        $normalized = $this->normalize($raw);

        if (null === $normalized) {
            return null;
        }

        return hash_hmac('sha256', $normalized, $this->secret, true);
    }
}
