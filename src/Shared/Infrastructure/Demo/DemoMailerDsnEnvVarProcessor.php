<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Demo;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Turns DEMO_MAIL into the mailer DSN of the demo environment (ADR 023): "mailpit" delivers to the
 * local Mailpit, "off" discards every mail (a demo on a server without Mailpit). Only these two
 * values exist, so no variable can point the demo at a real mail server.
 * Used as %env(demo_mailer_dsn:DEMO_MAIL)% in config/packages/demo/framework.yaml.
 *
 * @psalm-suppress UnusedClass
 */
#[When(env: 'demo')]
final class DemoMailerDsnEnvVarProcessor implements EnvVarProcessorInterface
{
    public const string PREFIX = 'demo_mailer_dsn';

    /** @var array<string, string> mode => mailer DSN */
    public const array DSNS = [
        'mailpit' => 'smtp://127.0.0.1:1025',
        'off' => 'null://null',
    ];

    #[\Override]
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $mode = $getEnv($name);
        if (!\is_string($mode) || !\array_key_exists($mode, self::DSNS)) {
            throw new RuntimeException(\sprintf('Invalid value for "%s": expected one of "%s".', $name, implode('", "', array_keys(self::DSNS))));
        }

        return self::DSNS[$mode];
    }

    #[\Override]
    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }
}
