<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference\Command;

use App\DataFixtures\Reference\IndicationGroupReferenceLoader;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reference:load-indication-groups',
    description: 'Load indication groups from fixtures/reference/indication_groups.yaml without purging the database.',
)]
final class LoadReferenceIndicationGroupsCommand extends Command
{
    private const string DEFAULT_USER = 'admin';

    public function __construct(
        private readonly IndicationGroupReferenceLoader $loader,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report planned changes without writing to the database')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Update existing groups (category and indication membership) to match the YAML')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Load only N groups (deterministic subset, same as dev fixtures)')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Username or email for createdBy on new groups', self::DEFAULT_USER);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $updateExisting = $input->getOption('update');
        $userOption = $input->getOption('user');

        $user = $this->resolveUser($userOption);
        if (!$user instanceof User) {
            $io->error(sprintf('No user found for "%s". Create a user first or pass --user=.', $userOption));

            return Command::FAILURE;
        }

        $definitions = $this->resolveDefinitions($input);
        if ([] === $definitions) {
            $io->warning('No indication group definitions to load.');

            return Command::SUCCESS;
        }

        $io->title('Load reference indication groups');
        $io->writeln(sprintf(
            'Definitions: %d | Mode: %s%s',
            \count($definitions),
            $updateExisting ? 'create + update' : 'create missing only',
            $dryRun ? ' (dry run)' : '',
        ));

        $result = $this->loader->syncGroups(
            $user,
            $definitions,
            updateExisting: $updateExisting,
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
    private function resolveDefinitions(InputInterface $input): array
    {
        $limit = $input->getOption('limit');
        if (null === $limit || '' === $limit) {
            return $this->loader->allDefinitions();
        }

        $count = (int) $limit;
        if ($count <= 0) {
            return [];
        }

        return $this->loader->pickDeterministicSubset($count);
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
