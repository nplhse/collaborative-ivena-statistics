<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\UI\Console;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Encounter\Application\SyntheticEncounterExports;
use App\DataFixtures\Encounter\Application\SyntheticEncounterKey;
use App\DataFixtures\Encounter\Application\SyntheticEncounterTruth;
use App\DataFixtures\Encounter\Infrastructure\SyntheticEncounterLoader;
use App\DataFixtures\FixtureVolumeResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:generate:encounter-files',
    description: 'Write synthetic ed_visit files of a hospital and month for the encounter upload: key file, two overlapping exports and the truth (#1052).',
)]
#[When(env: 'demo')]
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class GenerateEncounterFilesCommand
{
    public function __construct(
        private FixtureVolumeResolver $volumeResolver,
        private SyntheticEncounterLoader $loader,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        #[Autowire(param: 'app.encounter.data_prep.version')]
        private string $toolVersion,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Public id of the hospital whose synthetic allocations the visits belong to')]
        string $hospital = '',
        #[Option(description: 'Month (YYYY-MM)')]
        string $month = '',
        #[Option(description: 'Seed instead of the configured one')]
        ?int $seed = null,
        #[Option(description: 'Directory for the files; default: var/encounter-synthetic/<hospital>/<month>')]
        string $dir = '',
    ): int {
        $entity = Uuid::isValid($hospital)
            ? $this->entityManager->getRepository(Hospital::class)->findOneBy(['publicId' => Uuid::fromString($hospital)])
            : null;
        if (!$entity instanceof Hospital) {
            $io->error('Pass --hospital=<public id> of an existing hospital.');

            return Command::INVALID;
        }
        if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $io->error('Pass --month=YYYY-MM.');

            return Command::INVALID;
        }

        $volume = $this->volumeResolver->resolve();
        $seed ??= $volume->seed;
        $lastDay = $volume->lastDay($this->clock);
        $monthStart = new \DateTimeImmutable($month.'-01 00:00:00');
        if ($monthStart > $lastDay) {
            $io->error(sprintf('The month %s starts after the last day %s.', $month, $lastDay->format('Y-m-d')));

            return Command::INVALID;
        }

        $publicId = (string) $entity->getPublicId();
        $generated = $this->loader->generate($entity, $seed, $month, $lastDay);
        $key = SyntheticEncounterKey::forHospital($seed, $publicId);
        $dir = '' !== $dir ? $dir : sprintf('%s/var/encounter-synthetic/%s/%s', $this->projectDir, $publicId, $month);

        $filesystem = new Filesystem();
        $written = [];
        $keyFile = $dir.'/encounter-key.json';
        $filesystem->dumpFile($keyFile, json_encode($key->keyFile('Synthetic key '.$publicId, $lastDay->format('Y-m-d')), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
        $written[] = [$keyFile, '–'];
        foreach (SyntheticEncounterExports::overlapping($generated, $monthStart) as $export) {
            $file = sprintf('%s/ed_visit_synthetic_%s.csv', $dir, $export->name);
            $filesystem->dumpFile($file, $export->content($key, $this->toolVersion));
            $written[] = [$file, (string) \count($export->rows)];
        }
        $truthFile = $dir.'/truth.json';
        $filesystem->dumpFile($truthFile, json_encode(SyntheticEncounterTruth::of($generated, $key), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
        $written[] = [$truthFile, (string) \count($generated->visits)];

        $io->table(['File', 'Rows'], $written);
        $io->definitionList(...array_map(static fn (string $kind, int $count): array => [$kind => (string) $count], array_keys($generated->countsByKind()), $generated->countsByKind()));
        $io->note('Upload the parts in order as "Encounter data (beta)"; the hospital must not hold encounter data under another key.');

        return Command::SUCCESS;
    }
}
