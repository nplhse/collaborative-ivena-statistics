<?php

declare(strict_types=1);

namespace App\Shared\UI\Console\Command;

use App\Shared\Infrastructure\Maintenance\MaintenanceMode;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Switches the maintenance mode (#1680, ADR 046). Deployer runs `on` before the migrations and `off` after the
 * switch to the new release, unless the deploy keeps it on for the one-time commands.
 *
 * @psalm-suppress UnusedClass Registered through #[AsCommand].
 */
#[AsCommand(
    name: 'app:maintenance',
    description: 'Switch the maintenance mode on or off, or show its status. While it is on, only administrators can use the application.',
)]
final readonly class MaintenanceCommand
{
    private const array ACTIONS = ['on', 'off', 'status'];

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MaintenanceMode $maintenanceMode,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'on, off or status', suggestedValues: self::ACTIONS)]
        string $action = 'status',
    ): int {
        switch ($action) {
            case 'on':
                $this->maintenanceMode->enable();
                break;
            case 'off':
                $this->maintenanceMode->disable();
                break;
            case 'status':
                break;
            default:
                $io->error(\sprintf('Unknown action "%s". Use on, off or status.', $action));

                return Command::INVALID;
        }

        $since = $this->maintenanceMode->enabledSince();
        if (!$since instanceof \DateTimeImmutable) {
            $io->success('Maintenance mode is off.');
        } else {
            $io->warning(\sprintf(
                'Maintenance mode is on since %s UTC. Only administrators can use the application. Switch it off with "bin/console app:maintenance off".',
                $since->format('Y-m-d H:i:s'),
            ));
        }

        return Command::SUCCESS;
    }
}
