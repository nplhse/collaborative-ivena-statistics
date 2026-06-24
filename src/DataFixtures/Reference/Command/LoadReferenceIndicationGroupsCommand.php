<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference\Command;

use App\DataFixtures\Reference\IndicationGroupReferenceLoader;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reference:load-indication-groups',
    description: 'Load indication groups from fixtures/reference/indication_groups.yaml without purging the database.',
)]
final readonly class LoadReferenceIndicationGroupsCommand
{
    private const string DEFAULT_USER = 'admin';

    public function __construct(
        private IndicationGroupReferenceLoader $loader,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Report planned changes without writing to the database', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Update existing groups (category and indication membership) to match the YAML')]
        bool $update = false,
        #[Option(description: 'Load only N groups (deterministic subset, same as dev fixtures)')]
        ?int $limit = null,
        #[Option(description: 'Username or email for createdBy on new groups')]
        string $user = self::DEFAULT_USER,
    ): int {
        $resolvedUser = $this->resolveUser($user);
        if (!$resolvedUser instanceof User) {
            $io->error(sprintf('No user found for "%s". Create a user first or pass --user=.', $user));

            return Command::FAILURE;
        }

        $definitions = $this->resolveDefinitions($limit);
        if ([] === $definitions) {
            $io->warning('No indication group definitions to load.');

            return Command::SUCCESS;
        }

        $io->title('Load reference indication groups');
        $io->writeln(sprintf(
            'Definitions: %d | Mode: %s%s',
            \count($definitions),
            $update ? 'create + update' : 'create missing only',
            $dryRun ? ' (dry run)' : '',
        ));

        $result = $this->loader->syncGroups(
            $resolvedUser,
            $definitions,
            updateExisting: $update,
            dryRun: $dryRun,
        );

        $io->table(
            ['Action', 'Count'],
            [
                ['Created', (string) $result->created],
                ['Updated', (string) $result->updated],
                ['Skipped (already exist)', (string) $result->skipped],
            ],
        );

        if ([] !== $result->warnings) {
            $io->warning('Warnings:');
            $io->listing($result->warnings);
        }

        if ($dryRun) {
            $io->success('Dry run finished. Re-run without --dry-run to apply changes.');

            return Command::SUCCESS;
        }

        if ($result->hasChanges()) {
            $this->entityManager->flush();
        }

        $io->success($result->hasChanges()
            ? 'Indication groups loaded.'
            : 'Nothing to do — all selected groups already exist (use --update to refresh).');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{name: string, category: ?string, codes: list<string>}>
     */
    private function resolveDefinitions(?int $limit): array
    {
        if (null === $limit) {
            return $this->loader->allDefinitions();
        }

        if ($limit <= 0) {
            return [];
        }

        return $this->loader->pickDeterministicSubset($limit);
    }

    private function resolveUser(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ('' === $identifier) {
            $identifier = self::DEFAULT_USER;
        }

        return $this->userRepository->findOneBy(['username' => $identifier])
            ?? $this->userRepository->findOneBy(['email' => mb_strtolower($identifier)]);
    }
}
