<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

/**
 * The local mail catcher of compose.override.yaml (Mailpit): SMTP and web UI on this machine only.
 * Dev always delivers to it; the demo only with DEMO_MAIL_CATCHER=1 (ADR 023).
 */
final readonly class LocalMailCatcher
{
    public const string HOST = '127.0.0.1';

    public const int SMTP_PORT = 1025;

    public const string SMTP_DSN = 'smtp://127.0.0.1:1025';

    public const string WEB_UI_URL = 'http://127.0.0.1:8025';

    /**
     * @param int $smtpPort port to probe; only tests pass another one
     *
     * @psalm-suppress PossiblyUnusedMethod autowired; only the container and tests call it
     */
    public function __construct(
        private int $smtpPort = self::SMTP_PORT,
    ) {
    }

    /**
     * Whether a mailer DSN delivers to the catcher.
     */
    public static function receives(string $mailerDsn): bool
    {
        return self::SMTP_DSN === $mailerDsn;
    }

    public function isReachable(): bool
    {
        $socket = @fsockopen(self::HOST, $this->smtpPort, $errno, $errstr, 1.0);

        if (false === $socket) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
