<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\Catalog\CatalogCompatibility;
use App\DataFixtures\Generator\Application\Catalog\CatalogMismatchException;
use App\DataFixtures\Generator\Application\DemoMonthCsvExporter;
use App\DataFixtures\Pattern\Application\InvalidPatternPackageException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:demo:csv',
    description: 'Demo only: write one synthetic month of a hospital as IVENA CSV files for the normal upload (#791).',
)]
#[When(env: 'demo')]
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class ExportDemoCsvCommand
{
    public function __construct(
        private FixtureVolumeResolver $volumeResolver,
        private DemoMonthCsvExporter $exporter,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private CatalogCompatibility $catalog,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Public id of the hospital')]
        string $hospital = '',
        #[Option(description: 'Month to export (YYYY-MM); default: the first month after the hospital\'s data')]
        string $month = '',
        #[Option(description: 'Seed instead of the configured one')]
        ?int $seed = null,
        #[Option(description: 'Directory for the files; default: var/demo/csv')]
        string $dir = '',
    ): int {
        $entity = Uuid::isValid($hospital)
            ? $this->entityManager->getRepository(Hospital::class)->findOneBy(['publicId' => Uuid::fromString($hospital)])
            : null;
        if (!$entity instanceof Hospital) {
            $io->error('Pass --hospital=<public id> of an existing hospital.');

            return Command::INVALID;
        }
        if ('' !== $month && 1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $io->error('--month must look like YYYY-MM.');

            return Command::INVALID;
        }

        try {
            $this->catalog->assertCompatible();
        } catch (CatalogMismatchException|\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $volume = $this->volumeResolver->resolve();
        if (null !== $seed) {
            $volume = $volume->withSeed($seed);
        }
        $lastDay = $volume->lastDay($this->clock);
        $month = '' !== $month ? $month : $this->exporter->defaultMonth($entity, $lastDay);

        try {
            $files = $this->exporter->export($volume, $entity, $month, $lastDay, '' !== $dir ? $dir : $this->projectDir.'/var/demo/csv');
        } catch (\DomainException|InvalidPatternPackageException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(['File', 'Rows', 'Upload as'], [
            [$files->allocationsPath, (string) $files->allocations, 'allocations (first)'],
            [$files->closuresPath, (string) $files->closures, 'closures (after the allocations are processed; needs the closure beta role)'],
        ]);
        if (0 === $files->closures) {
            $io->note('The month has no closures; the closure file only holds the header and need not be uploaded.');
        }
        $io->success(sprintf('%s, %s: upload both files for "%s" through the import page.', (string) $entity->getName(), $files->month, (string) $entity->getName()));

        return Command::SUCCESS;
    }
}
