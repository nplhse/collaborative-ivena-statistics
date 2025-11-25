<?php

namespace App\Import\UI\Console\Command;

use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

#[AsCommand(
    name: 'app:import:allocations',
    description: 'Dispatch an allocation import job via Messenger',
)]
final class ImportAllocationsCommand extends Command
{
    public function __construct(
        private readonly ImportRepository $importRepository,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
        private \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $tokenStorage,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('importId', InputArgument::REQUIRED, 'ID of the Import entity (required)')
            ->addOption('userId', null, InputOption::VALUE_REQUIRED, 'User ID to set as createdBy (required)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Fetch command options
        $importId = (int) $input->getArgument('importId');
        $userId = (int) $input->getOption('userId');

        $import = $this->importRepository->find($importId);

        if (!$import instanceof Import) {
            $output->writeln(sprintf('<error>No Import found with ID %d</error>', $importId));

            return Command::FAILURE;
        }

        $user = $this->em->getRepository(User::class)->find($userId);

        if (null === $user) {
            $output->writeln(sprintf('<error>User #%d not found.</error>', $userId));

            return Command::FAILURE;
        }

        $token = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);

        try {
            // create message
            $message = new ImportAllocationsMessage($importId);

            // dispatch via Messenger (sync or async depending on transport config)
            $this->bus->dispatch($message);

            $output->writeln(sprintf(
                '<info>Dispatched import job for Import #%d"</info>',
                $importId,
            ));
        } finally {
            $this->tokenStorage->setToken(null);
        }

        return Command::SUCCESS;
    }
}
