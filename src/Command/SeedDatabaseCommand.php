<?php

// src/Command/SeedDatabaseCommand.php

namespace App\Command;

use App\Entity\Assignment;
use App\Entity\Department;
use App\Entity\IndicationNormalized;
use App\Entity\IndicationRaw;
use App\Entity\Infection;
use App\Entity\Occasion;
use App\Entity\Speciality;
use App\Entity\User;
use App\Service\Import\Indication\IndicationKey;
use App\Service\Seed\SeedProviderInterface;
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
     * @param iterable<SeedProviderInterface<mixed>> $providers
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        /** @var iterable<SeedProviderInterface<mixed>> */
        #[AutowireIterator(tag: 'app.seed_provider')]
        private readonly iterable $providers,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'User ID to set as createdBy (required)')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Truncate target tables before seeding')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be inserted, do not write');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getOption('user-id');
        if (null === $userId || '' === $userId || !ctype_digit($userId)) {
            $output->writeln('<error>--user-id is required and must be a positive integer.</error>');

            return Command::INVALID;
        }

        $dryRun = $input->getOption('dry-run');
        $purge = $input->getOption('purge');

        if ($purge) {
            $this->purgeTables($output);
        }

        if ($dryRun) {
            foreach ($this->providers as $provider) {
                $output->writeln(sprintf('<info>[DRY]</info> %s:', $provider->getType()));
                foreach ($provider->provide() as $value) {
                    $output->writeln("  - $value");
                }
            }

            return Command::SUCCESS;
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if (null === $user) {
            $output->writeln(sprintf('<error>User #%d not found.</error>', $userId));

            return Command::FAILURE;
        }

        foreach ($this->providers as $provider) {
            $output->writeln(sprintf('<info>Seeding %s...</info>', $provider->getType()));

            $type = $provider->getType();

            switch ($type) {
                case 'speciality':
                case 'department':
                case 'assignment':
                case 'occasion':
                case 'infection':
                    /** @var iterable<string> $values */
                    $values = $provider->provide();

                    $make = [
                        'speciality' => fn (string $v): Speciality => new Speciality()->setName($v),
                        'department' => fn (string $v): Department => new Department()->setName($v),
                        'assignment' => fn (string $v): Assignment => new Assignment()->setName($v),
                        'occasion' => fn (string $v): Occasion => new Occasion()->setName($v),
                        'infection' => fn (string $v): Infection => new Infection()->setName($v),
                    ];

                    foreach ($values as $value) {
                        $entity = $make[$type]($value);
                        $entity->setCreatedBy($user);
                        $this->em->persist($entity);
                    }
                    break;

                case 'indication_raw':
                case 'indication_normalized':
                    /** @var iterable<array{name:string, code:string}> $values */
                    $values = $provider->provide();

                    if ('indication_raw' === $type) {
                        foreach ($values as $value) {
                            $entity = new IndicationRaw()
                                ->setName($value['name'])
                                ->setCode((int) $value['code'])
                                ->setHash(IndicationKey::hashFrom($value['code'], $value['name']));
                            $entity->setCreatedBy($user);
                            $this->em->persist($entity);
                        }
                    } else {
                        foreach ($values as $value) {
                            $entity = new IndicationNormalized()
                                ->setName($value['name'])
                                ->setCode((int) $value['code']);
                            $entity->setCreatedBy($user);
                            $this->em->persist($entity);
                        }
                    }
                    break;

                default:
                    throw new \RuntimeException('Unknown seed type: '.$type);
            }
        }

        $this->em->flush();

        $output->writeln('<info>Seeding finished.</info>');

        return Command::SUCCESS;
    }

    private function purgeTables(OutputInterface $output): void
    {
        $tables = ['speciality', 'department'];

        $sql = sprintf(
            'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
            implode(', ', array_map(fn ($t) => "\"$t\"", $tables))
        );

        $output->writeln('<comment>TRUNCATE:</comment> '.$sql);
        $this->em->getConnection()->executeStatement($sql);
    }
}
