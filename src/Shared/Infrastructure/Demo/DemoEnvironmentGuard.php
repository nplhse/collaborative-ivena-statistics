<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Demo;

use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Stops every request and console command of the demo environment (ADR 023) before it can touch a
 * database that is not a local "*_demo" database, e.g. when DATABASE_URL is set on the machine.
 * Reads the same DATABASE_URL as the Doctrine connection (config/packages/doctrine.yaml).
 * It also resolves the demo mailer DSN, so an invalid DEMO_MAIL_CATCHER stops the demo right away
 * instead of at the first mail.
 * Registered only in the demo environment; dev, test and prod never load it.
 *
 * @psalm-suppress UnusedClass
 */
#[When(env: 'demo')]
final readonly class DemoEnvironmentGuard
{
    public function __construct(
        #[Autowire(env: 'resolve:DATABASE_URL')]
        private string $databaseUrl,
        #[Autowire(param: 'app.mailer_dsn')]
        private string $mailerDsn,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->assertSafeEnvironment();
        }
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 4096)]
    public function onConsoleCommand(): void
    {
        $this->assertSafeEnvironment();
    }

    private function assertSafeEnvironment(): void
    {
        $violations = DemoDatabaseTarget::violations(new DsnParser()->parse($this->databaseUrl));
        if ([] !== $violations) {
            throw new \RuntimeException(\sprintf('The demo environment refuses this database: %s. Check DATABASE_URL in .env.demo and .env.demo.local and the variables set on this machine.', implode('; ', $violations)));
        }

        if (!\in_array($this->mailerDsn, DemoMailerDsnEnvVarProcessor::DSNS, true)) {
            throw new \RuntimeException('The demo environment only sends mail to the local mail catcher. Set DEMO_MAIL_CATCHER to 0 or 1 and do not change app.mailer_dsn.');
        }
    }
}
