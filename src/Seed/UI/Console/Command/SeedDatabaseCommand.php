<?php

namespace App\Seed\UI\Console\Command;

use App\Seed\Application\Contracts\SeedProviderInterface;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(
    name: 'app:seed:database',
    description: 'Seed initial reference data into the database'
)]
final class SeedDatabaseCommand extends Command
{
    /**
     * @var iterable<SeedProviderInterface<mixed>>
     */
    private iterable $providers;

    /**
     * @param iterable<SeedProviderInterface<mixed>> $providers
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[AutowireIterator(tag: 'app.seed_provider')]
        iterable $providers,
    ) {
        $this->providers = $providers;
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'User ID to set as createdBy (required)')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Truncate target tables before seeding')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Fetch command options
        $userId = $input->getOption('user-id');
        $purge = $input->getOption('purge');

        if (null === $userId || '' === $userId || !ctype_digit($userId)) {
            $output->writeln('<error>--user-id is required and must be a positive integer.</error>');

            return Command::INVALID;
        }

        $user = $this->em->getRepository(User::class)->find($userId);

        if (null === $user) {
            $output->writeln(sprintf('<error>User #%d not found.</error>', $userId));

            return Command::FAILURE;
        }

        if ($purge) {
            $this->purgeTablesFromProviders($output);
        }

        foreach ($this->providers as $provider) {
            $output->writeln(sprintf('<info>Seeding %s...</info>', $provider->getType()));

            foreach ($provider->build($user) as $entity) {
                $this->em->persist($entity);
            }

            $this->em->flush();
        }

        $output->writeln('<info>Seeding finished.</info>');

        return Command::SUCCESS;
    }

    private function purgeTablesFromProviders(OutputInterface $output): void
    {
        $conn = $this->em->getConnection();
        $tables = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->purgeTables() as $table) {
                $tables[$table] = true;
            }
        }

        if ([] === $tables) {
            $output->writeln('<comment>No tables to purge.</comment>');

            return;
        }

        $quotedTables = array_map(fn (string $t) => $this->quoteTableName($conn, $t), array_keys($tables));

        $sql = sprintf('TRUNCATE %s RESTART IDENTITY CASCADE;', implode(', ', $quotedTables));
        $output->writeln('<info>Purged tables:</info> '.implode(', ', array_keys($tables)));
        $conn->executeStatement($sql);
    }

    private function quoteTableName(Connection $conn, string $name): string
    {
        if (str_contains($name, '.')) {
            $parts = explode('.', $name, 2);
            $schema = $parts[0] ?? '';
            $table = $parts[1] ?? '';

            return $conn->quoteSingleIdentifier($schema).'.'.$conn->quoteSingleIdentifier($table);
        }

        return $conn->quoteSingleIdentifier($name);
    }
}
