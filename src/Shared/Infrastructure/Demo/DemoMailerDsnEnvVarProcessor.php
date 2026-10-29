<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Demo;

use App\Shared\Infrastructure\Mail\LocalMailCatcher;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Turns the demo opt-in DEMO_MAIL_CATCHER into the mailer DSN of the demo environment (ADR 023):
 * "0" or empty keeps mail off, "1" delivers to the local mail catcher. Host and port are fixed
 * here, so no value can make the demo deliver to a real mail server; any other value fails.
 *
 * Usage: %env(demo_mailer_dsn:DEMO_MAIL_CATCHER)%
 *
 * @psalm-suppress UnusedClass
 */
#[When(env: 'demo')]
final readonly class DemoMailerDsnEnvVarProcessor implements EnvVarProcessorInterface
{
    public const string PREFIX = 'demo_mailer_dsn';

    public const string DISABLED_DSN = 'null://null';

    /** Every DSN this processor can return. */
    public const array DSNS = [self::DISABLED_DSN, LocalMailCatcher::SMTP_DSN];

    public static function dsnFor(?string $optIn): string
    {
        return match (trim((string) $optIn)) {
            '', '0' => self::DISABLED_DSN,
            '1' => LocalMailCatcher::SMTP_DSN,
            default => throw new RuntimeException(\sprintf('DEMO_MAIL_CATCHER must be "0" (mail off) or "1" (local mail catcher on %s), got "%s".', LocalMailCatcher::SMTP_DSN, (string) $optIn)),
        };
    }

    #[\Override]
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        /** @var scalar|null $value */
        $value = $getEnv($name);

        return self::dsnFor(null === $value ? null : (string) $value);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }
}
