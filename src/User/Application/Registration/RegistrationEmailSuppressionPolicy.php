<?php

declare(strict_types=1);

namespace App\User\Application\Registration;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RegistrationEmailSuppressionPolicy
{
    /**
     * @param list<string> $blockedEmailDomains
     *
     * @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI container.
     */
    public function __construct(
        #[Autowire(param: 'app.registration.blocked_email_domains')]
        private array $blockedEmailDomains,
    ) {
    }

    public function matchedSuffix(string $email): ?string
    {
        $domain = $this->extractDomain($email);
        if (null === $domain) {
            return null;
        }

        foreach ($this->blockedEmailDomains as $rule) {
            $suffix = $this->normalizeSuffix($rule);
            if ('' === $suffix) {
                continue;
            }

            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return $suffix;
            }
        }

        return null;
    }

    private function extractDomain(string $email): ?string
    {
        $normalized = mb_strtolower(trim($email));
        $atPos = strrpos($normalized, '@');
        if (false === $atPos || $atPos === \strlen($normalized) - 1) {
            return null;
        }

        $domain = rtrim(substr($normalized, $atPos + 1), '.');

        return '' === $domain ? null : $domain;
    }

    private function normalizeSuffix(string $rule): string
    {
        return trim(mb_strtolower(trim($rule)), '.');
    }
}
