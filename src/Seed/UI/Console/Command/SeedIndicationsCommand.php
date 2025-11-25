<?php

declare(strict_types=1);

namespace App\Seed\UI\Console\Command;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Import\Infrastructure\Indication\IndicationKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:indications',
    description: 'Create IndicationRaw for all existing IndicationNormalized and connect them.'
)]
final class SeedIndicationsCommand extends Command
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IndicationNormalizedRepository $normalizedRepo,
        private readonly IndicationRawRepository $rawRepo,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Creating IndicationRaw from existing IndicationNormalized');

        $total = (int) $this->normalizedRepo->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if (0 === $total) {
            $io->warning('No IndicationNormalized found.');

            return Command::SUCCESS;
        }

        $progress = new ProgressBar($output, $total);
        $progress->start();

        $iterable = $this->normalizedRepo->createQueryBuilder('n')
            ->orderBy('n.id', 'ASC')
            ->getQuery()
            ->toIterable();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $processed = 0;

        /** @var IndicationNormalized $normalized */
        foreach ($iterable as $normalized) {
            ++$processed;

            $hash = IndicationKey::hashFrom((string) $normalized->getCode(), $normalized->getName());
            $existing = $this->rawRepo->findOneBy(['hash' => $hash]);

            if (null !== $existing) {
                $changed = false;

                if ($existing->getTarget()?->getId() !== $normalized->getId()) {
                    $existing->setTarget($normalized);
                    $changed = true;
                }
                if ($existing->getNormalized()?->getId() !== $normalized->getId()) {
                    $existing->setNormalized($normalized);
                    $changed = true;
                }

                if ($changed) {
                    $this->em->persist($existing);
                    ++$updated;
                } else {
                    ++$skipped;
                }
            } else {
                $code = $normalized->getCode();
                assert(null !== $code);

                $name = $normalized->getName();
                assert(null !== $name);

                $createdBy = $normalized->getCreatedBy();
                assert(null !== $createdBy);

                $raw = new IndicationRaw();
                $raw->setCode($code);
                $raw->setName($name);
                $raw->setHash($hash);
                $raw->setTarget($normalized);
                $raw->setNormalized($normalized);
                $raw->setCreatedBy($createdBy);

                $this->em->persist($raw);
                ++$created;
            }

            if (0 === $processed % self::BATCH_SIZE) {
                $this->em->flush();
                $this->em->clear();
            }

            $progress->advance();
        }

        $this->em->flush();
        $this->em->clear();

        $progress->finish();
        $output->writeln('');

        $io->success(sprintf(
            'Completed. Total: %d | New: %d | Updated: %d | Unchanged: %d',
            $processed,
            $created,
            $updated,
            $skipped
        ));

        return Command::SUCCESS;
    }
}
