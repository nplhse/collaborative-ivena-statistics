<?php

declare(strict_types=1);

namespace App\Install\UI\Console\Command;

use App\Install\Application\Environment\EnvironmentChecker;
use App\Install\Application\Environment\EnvironmentCheckItem;
use App\Install\Application\Environment\EnvironmentCheckProfile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:env:check',
    description: 'Validate required environment variables for production/beta deployment.',
)]
final readonly class EnvCheckCommand
{
    private const array TRACKED_VARIABLES = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_SECRET',
        'DATABASE_URL',
        'APP_URL',
        'MAILER_DSN',
        'MAILER_FROM',
        'MESSENGER_TRANSPORT_DSN',
        'SENTRY_DSN',
        'SENTRY_ENVIRONMENT',
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EnvironmentChecker $environmentChecker,
        #[Autowire('%kernel.environment%')]
        private string $kernelEnvironment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Validation profile: prod, beta, or dev', name: 'check-profile')]
        ?string $checkProfile = null,
        #[Option(description: 'Skip database connectivity check', name: 'skip-database')]
        bool $skipDatabase = false,
    ): int {
        $resolvedProfile = $this->resolveProfile($checkProfile);
        $variables = $this->collectVariables();
        $report = $this->environmentChecker->check($resolvedProfile, $variables, $skipDatabase);

        $io->title(sprintf('Environment check (%s profile)', $resolvedProfile->value));
        $io->table(
            ['Variable', 'Status', 'Message'],
            array_map(
                static fn (EnvironmentCheckItem $item): array => [
                    $item->variable,
                    $item->status->value,
                    $item->message,
                ],
                $report->items,
            ),
        );

        if ($report->hasFailures()) {
            $io->error('Environment check failed.');

            return Command::FAILURE;
        }

        if ($report->hasWarnings()) {
            $io->warning('Environment check passed with warnings.');
        } else {
            $io->success('Environment check passed.');
        }

        return Command::SUCCESS;
    }

    private function resolveProfile(?string $profile): EnvironmentCheckProfile
    {
        if (null !== $profile && '' !== $profile) {
            return EnvironmentCheckProfile::from($profile);
        }

        return 'prod' === $this->kernelEnvironment
            ? EnvironmentCheckProfile::Prod
            : EnvironmentCheckProfile::Dev;
    }

    /**
     * @return array<string, string>
     */
    private function collectVariables(): array
    {
        $variables = [];

        foreach (self::TRACKED_VARIABLES as $name) {
            $variables[$name] = $this->readVariable($name);
        }

        return $variables;
    }

    private function readVariable(string $name): string
    {
        $envValue = $_ENV[$name] ?? null;
        if (is_string($envValue)) {
            return $envValue;
        }

        $serverValue = $_SERVER[$name] ?? null;
        if (is_string($serverValue)) {
            return $serverValue;
        }

        $value = getenv($name);

        return false === $value ? '' : (string) $value;
    }
}
