<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Generator\Application\Dataset\DatasetGenerator;
use App\DataFixtures\Generator\Application\Dataset\DatasetPeriod;
use App\DataFixtures\Generator\Application\Dataset\DatasetRequest;
use App\DataFixtures\Generator\Application\Dataset\ExistingData;
use App\DataFixtures\Generator\Application\Dataset\ProfileSelectionCodec;
use App\DataFixtures\Generator\Application\ImportBatchPlanner;
use App\DataFixtures\Generator\Application\ProfileSelection;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:generate:dataset',
    description: 'Development and demo only: generate synthetic data for one, several or all participating hospitals over a period (ADR 022).',
)]
#[When(env: 'dev')]
#[When(env: 'test')]
#[When(env: 'demo')]
final readonly class GenerateDatasetCommand
{
    public function __construct(
        private DatasetConsole $console,
        private DatasetGenerator $datasets,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $hospital
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Public id of a hospital (repeatable)')]
        array $hospital = [],
        #[Option(description: 'Every hospital marked as participating', name: 'all-participating')]
        bool $allParticipating = false,
        #[Option(description: 'First month (YYYY-MM); default: --months before --to')]
        string $from = '',
        #[Option(description: 'Last month (YYYY-MM); default: the month before last, so the last complete month stays for app:demo:csv')]
        string $to = '',
        #[Option(description: 'Number of months when --from is not given')]
        int $months = 12,
        #[Option(description: 'Seed instead of the configured one')]
        ?int $seed = null,
        #[Option(description: 'Pattern source instead of FIXTURES_PATTERNS: "defaults" or a package')]
        string $patterns = '',
        #[Option(description: 'Generated data already in the period: refuse, fill (keep complete months) or replace')]
        string $existing = 'refuse',
        #[Option(description: 'One hospital only: allocations per year instead of the drawn volume', name: 'annual-volume')]
        ?int $annualVolume = null,
        #[Option(description: 'One hospital only: share of closed department-hours, 0 to below 1', name: 'closure-share')]
        ?float $closureShare = null,
        #[Option(description: 'One hospital only: hospital configuration of the structural patterns')]
        string $configuration = '',
        #[Option(description: 'JSON file: hospital public id => profile fields (annual_volume, closure_share, configuration, cathlab, …)')]
        string $profiles = '',
        #[Option(description: 'Show the plan without writing anything', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Notify the owner of each hospital in-app once its data is fully processed')]
        bool $notify = false,
        #[Option(description: 'Leave blocked hospitals out instead of refusing the run', name: 'skip-blocked')]
        bool $skipBlocked = false,
        #[Option(description: 'Run id: generate the hospitals again that failed during generation of that run, with its recorded settings in mode fill')]
        string $retry = '',
    ): int {
        if ('' !== $retry) {
            try {
                $request = $this->datasets->retryRequest($retry);
            } catch (\DomainException $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }

            return $this->console->run($io, $request, $dryRun, $skipBlocked);
        }
        if ($allParticipating === ([] !== $hospital)) {
            $io->error('Choose the scope: --hospital=<public id> (repeatable) or --all-participating.');

            return Command::INVALID;
        }
        $mode = ExistingData::tryFrom($existing);
        if (!$mode instanceof ExistingData) {
            $io->error('--existing takes refuse, fill or replace.');

            return Command::INVALID;
        }

        try {
            $ids = [];
            foreach ($hospital as $publicId) {
                $ids[] = $this->hospitalId($publicId);
            }
            $ids = array_values(array_unique($ids));
            $selections = $this->selections($ids, $profiles, $annualVolume, $closureShare, $configuration);
            $period = $this->period($from, $to, $months);
            $request = new DatasetRequest(
                scope: $allParticipating ? GenerationRunScope::AllParticipating : (1 === \count($ids) ? GenerationRunScope::One : GenerationRunScope::Selected),
                hospitalIds: $ids,
                period: $period,
                seed: $seed,
                existing: $mode,
                selections: $selections,
                patterns: '' === $patterns ? null : $patterns,
                notify: $notify,
            );
        } catch (\InvalidArgumentException|\JsonException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        return $this->console->run($io, $request, $dryRun, $skipBlocked);
    }

    private function period(string $from, string $to, int $months): DatasetPeriod
    {
        if ('' === $from && '' === $to) {
            return DatasetPeriod::demoDefault($this->clock, $months);
        }
        if ('' === $to) {
            throw new \InvalidArgumentException('--from needs --to.');
        }
        if ('' === $from) {
            $last = ImportBatchPlanner::firstDay($to);
            $from = $last->modify(\sprintf('-%d months', max(1, $months) - 1))->format('Y-m');
        }

        return DatasetPeriod::between($from, $to);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, ProfileSelection>
     *
     * @throws \JsonException
     */
    private function selections(array $ids, string $file, ?int $annualVolume, ?float $closureShare, string $configuration): array
    {
        $flags = null !== $annualVolume || null !== $closureShare || '' !== $configuration;
        if ($flags && 1 !== \count($ids)) {
            throw new \InvalidArgumentException('--annual-volume, --closure-share and --configuration take exactly one --hospital; use --profiles for several.');
        }
        if ($flags && '' !== $file) {
            throw new \InvalidArgumentException('Pass either the profile options or --profiles.');
        }
        if ($flags) {
            return [$ids[0] => new ProfileSelection(configuration: '' === $configuration ? null : $configuration, annualVolume: $annualVolume, closureShare: $closureShare)];
        }
        if ('' === $file) {
            return [];
        }

        $content = is_file($file) ? file_get_contents($file) : false;
        if (false === $content) {
            throw new \InvalidArgumentException(\sprintf('Cannot read the profile file "%s".', $file));
        }
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('The profile file holds an object: hospital public id => profile fields.');
        }
        $selections = [];
        foreach ($data as $publicId => $fields) {
            if (!\is_array($fields)) {
                throw new \InvalidArgumentException(\sprintf('The profile of %s is not an object.', (string) $publicId));
            }
            $id = $this->hospitalId((string) $publicId);
            if ([] !== $ids && !\in_array($id, $ids, true)) {
                throw new \InvalidArgumentException(\sprintf('The profile file names %s, which is not a selected --hospital.', (string) $publicId));
            }
            $selections[$id] = ProfileSelectionCodec::decode($fields);
        }

        return $selections;
    }

    private function hospitalId(string $publicId): int
    {
        $hospital = Uuid::isValid($publicId) ? $this->entityManager->getRepository(Hospital::class)->findOneBy(['publicId' => Uuid::fromString($publicId)]) : null;
        if (!$hospital instanceof Hospital) {
            throw new \InvalidArgumentException(\sprintf('Unknown hospital "%s".', $publicId));
        }

        return (int) $hospital->getId();
    }
}
