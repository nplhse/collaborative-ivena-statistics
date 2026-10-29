<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Faker\Provider\Hospital as HospitalFaker;
use App\DataFixtures\Generator\Application\Dataset\ExistingData;
use App\DataFixtures\Generator\Application\Demo\DemoHospitalRequest;
use App\DataFixtures\Generator\Application\Demo\DemoHospitalSetup;
use App\DataFixtures\Generator\Application\Demo\DemoHospitalWizard;
use App\DataFixtures\Generator\Application\Demo\DemoProvisioning;
use App\DataFixtures\Generator\Application\Demo\DemoWizardRefused;
use App\DataFixtures\Generator\Application\Demo\NewDemoHospital;
use App\DataFixtures\Generator\Application\Run\GenerationRunStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * The presenter's wizard (#791 step 4): a new synthetic hospital or an existing hospital of the demo database, a
 * participant account, and a synthetic dataset with live progress. Every answer can also be passed as an option, so a
 * presentation can be prepared as a script.
 */
#[AsCommand(
    name: 'app:demo:hospital:create',
    description: 'Demo only: create a participant and a hospital (or use an existing one) and generate its synthetic dataset (#791).',
)]
#[When(env: 'dev')]
#[When(env: 'test')]
#[When(env: 'demo')]
final readonly class CreateDemoHospitalCommand
{
    private const string NEW = 'A new synthetic hospital';
    private const string EXISTING = 'An existing hospital of the demo database';
    private const string ENTER_COORDINATES = 'Enter coordinates';
    private const string NO_LOCATION = 'No prepared location (fetched online when an OpenRouteService key is set)';

    public function __construct(
        private DemoHospitalWizard $wizard,
        private Connection $connection,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'Public id of an existing hospital of the demo database (instead of a new one)')]
        string $hospital = '',
        #[Option(description: 'Name of the new hospital')]
        string $name = '',
        #[Option(description: 'Emergency care tier of the new hospital: basic, extended or full')]
        string $tier = '',
        #[Option(description: 'Beds of the new hospital (the size follows from the bed bands of the synthetic catalog)')]
        ?int $beds = null,
        #[Option(description: 'Dispatch area of the new hospital (name or id); the federal state follows from it', name: 'dispatch-area')]
        string $dispatchArea = '',
        #[Option(description: 'Setting of the new hospital: urban, mixed or rural', name: 'location-type')]
        string $locationType = 'mixed',
        #[Option(description: 'Expected EMS allocations per year', name: 'annual-volume')]
        ?int $annualVolume = null,
        #[Option(description: 'Simulation period in months: 6, 12 or 24, ending with the month before last')]
        ?int $months = null,
        #[Option(description: 'Share of closed department-hours, 0 to below 1 (0.05 = 5 %)', name: 'closure-share')]
        ?float $closureShare = null,
        #[Option(description: 'Slug of a prepared location (app:geo:list-prepared-locations)')]
        string $location = '',
        #[Option(description: 'Latitude of the new hospital, without a prepared location')]
        ?float $latitude = null,
        #[Option(description: 'Longitude of the new hospital, without a prepared location')]
        ?float $longitude = null,
        #[Option(description: 'Generated data of the hospital already in the period: refuse or replace (real imports are never touched)')]
        string $existing = 'refuse',
        #[Option(description: 'Do not ask for confirmation')]
        bool $yes = false,
    ): int {
        $io->title('Demo hospital wizard');
        $io->text([
            'Creates a participant account and generates a synthetic dataset for one hospital.',
            'All allocations and closures are synthetic, also for a hospital with a real name and location.',
        ]);

        try {
            $request = $this->askRequest($io, $input, $hospital, $name, $tier, $beds, $dispatchArea, $locationType, $annualVolume, $months, $closureShare, $location, $latitude, $longitude, $existing);
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $this->renderSummary($io, $request);
        if ('demo' !== $this->environment) {
            // Without APP_ENV=demo the wizard writes into the development database, which looks alike (same seed).
            $io->warning(\sprintf('This is the %s environment, not the demo: the wizard writes into the database "%s". For the demo database start it with APP_ENV=demo.', $this->environment, $this->database()));
        }
        if (!$yes && !$io->confirm('Create the participant and generate the dataset?', true)) {
            $io->warning('Nothing was written.');

            return Command::SUCCESS;
        }

        return $this->run($io, $output, $request);
    }

    private function run(SymfonyStyle $io, OutputInterface $output, DemoHospitalRequest $request): int
    {
        $io->section('Generating');
        $progress = new DemoWizardProgress($output);
        $progress->set(DemoWizardProgress::PARTICIPANT, StageState::Running, 'creating the account');
        $progress->set(DemoWizardProgress::HOSPITAL, StageState::Running, $request->isNewHospital() ? 'creating the hospital' : 'checking the hospital');

        try {
            $provisioning = $this->wizard->provision($request);
        } catch (DemoWizardRefused $refused) {
            $progress->set(DemoWizardProgress::PARTICIPANT, StageState::Failed, 'rolled back');
            $progress->set(DemoWizardProgress::HOSPITAL, StageState::Failed, 'rolled back');
            $io->error(['Nothing was written. The dataset cannot be generated:', ...$refused->reasons]);
            if (ExistingData::Refuse === $request->existing && str_contains(implode(' ', $refused->reasons), 'choose fill or replace')) {
                $io->note('The hospital already has generated data in the period. Run the wizard again with --existing=replace to generate it anew (real imports are never touched).');
            }

            return Command::FAILURE;
        }

        $progress->set(DemoWizardProgress::PARTICIPANT, StageState::Completed, \sprintf('%s (%s)', $provisioning->participant->username, $provisioning->participant->email));
        $progress->set(DemoWizardProgress::HOSPITAL, StageState::Completed, $request->isNewHospital()
            ? \sprintf('%s created', $provisioning->hospitalName)
            : \sprintf('%s: %s', $provisioning->hospitalName, [] === $provisioning->changes ? 'master data kept' : 'master data kept, '.implode(', ', $provisioning->changes)));

        $this->ensureLocation($progress, $provisioning);

        $plan = $provisioning->plan->hospitals[0];
        $progress->plan(\count($provisioning->months()), $plan->plannedAllocations);
        $runId = $this->wizard->start($provisioning);
        $result = $this->wizard->generate($runId, $progress);

        $run = $result->run;
        $hospitalRun = $run->hospitals[0] ?? null;
        if ($result->hasFailures()) {
            $io->error([
                'No dataset was generated: the months written for the hospital were rolled back.',
                'Reason: '.GenerationRunFormatter::error($hospitalRun?->error, $io->isVerbose()),
                'Nothing was handed to the Messenger worker, so the run has no background jobs.',
            ]);
            $this->renderResult($io, $provisioning, $runId, $result->run?->status, failed: true);

            return Command::FAILURE;
        }
        if (GenerationRunStatus::Completed === $run?->status) {
            $progress->set(DemoWizardProgress::PROJECTIONS, StageState::Completed, 'statistics are up to date; the owner was notified');
        } else {
            $progress->set(DemoWizardProgress::PROJECTIONS, StageState::Background, 'handed to the Messenger worker; the owner is notified when done');
        }

        $this->renderResult($io, $provisioning, $runId, $run?->status, $hospitalRun?->allocations, $hospitalRun?->closures, $hospitalRun?->mciCases);

        return Command::SUCCESS;
    }

    private function ensureLocation(DemoWizardProgress $progress, DemoProvisioning $provisioning): void
    {
        $progress->set(DemoWizardProgress::LOCATION, StageState::Running, 'checking the geodata');
        $outcome = $this->wizard->ensureLocation($provisioning);
        // The dataset does not depend on the location: missing geodata is information, not a failure.
        $progress->set(DemoWizardProgress::LOCATION, $outcome->isComplete() ? StageState::Completed : StageState::Skipped, $outcome->detail);
    }

    private function askRequest(
        SymfonyStyle $io,
        InputInterface $input,
        string $hospital,
        string $name,
        string $tier,
        ?int $beds,
        string $dispatchArea,
        string $locationType,
        ?int $annualVolume,
        ?int $months,
        ?float $closureShare,
        string $location,
        ?float $latitude,
        ?float $longitude,
        string $existing,
    ): DemoHospitalRequest {
        $interactive = $input->isInteractive();
        $existingData = ExistingData::tryFrom($existing);
        if (!\in_array($existingData, [ExistingData::Refuse, ExistingData::Replace], true)) {
            throw new \InvalidArgumentException('--existing takes refuse or replace.');
        }
        $slug = '' === $location ? null : $location;

        $useExisting = '' !== $hospital || ('' === $name && $interactive && self::EXISTING === $io->choice('Which hospital should the demo show?', [self::NEW, self::EXISTING], self::NEW));

        if ($useExisting) {
            $chosen = '' !== $hospital ? $this->hospitalByPublicId($hospital) : $this->askHospital($io);
            $this->renderHospital($io, $chosen);
            $new = null;
            $existingId = (int) $chosen->getId();
            $defaultVolume = $this->defaultVolume((int) $chosen->getBeds());
            if (null === $slug && $interactive && !$this->wizard->hasGeodata($chosen)) {
                [$slug] = $this->askLocation($io, allowCoordinates: false);
            }
        } else {
            if ('' === $name) {
                $name = $interactive ? $this->askName($io) : throw new \InvalidArgumentException('Pass --name for a new hospital or --hospital for an existing one.');
            } elseif ($this->wizard->hospitalNameTaken($name)) {
                throw new \InvalidArgumentException(\sprintf('A hospital named "%s" already exists. Choose another name or pass --hospital.', $name));
            }
            $tierEnum = '' !== $tier ? $this->tier($tier) : HospitalTier::from($this->choice($io, $interactive, 'Emergency care tier', array_values(HospitalTier::getValues()), HospitalTier::EXTENDED->value));
            $beds ??= $interactive ? (int) $io->ask('Beds', '400', $this->positiveInt(...)) : throw new \InvalidArgumentException('Pass --beds.');
            $area = $this->dispatchArea($io, $interactive, $dispatchArea);
            $setting = HospitalLocation::tryFrom(ucfirst(strtolower($locationType))) ?? throw new \InvalidArgumentException('--location-type takes urban, mixed or rural.');
            if (null === $slug && null === $latitude && $interactive) {
                [$slug, $latitude, $longitude] = $this->askLocation($io, allowCoordinates: true);
            }
            $new = new NewDemoHospital($name, $tierEnum, $beds, (int) $area->getId(), $setting, $latitude, $longitude);
            $existingId = null;
            $defaultVolume = $this->defaultVolume($beds);
        }

        $annualVolume ??= (int) $this->ask($io, $interactive, 'Expected EMS allocations per year', (string) $defaultVolume, $this->positiveInt(...));
        $months ??= (int) $this->choice($io, $interactive, 'Simulation period in months (ending with the month before last)', array_map(strval(...), DemoHospitalRequest::MONTHS), '12');
        $closureShare ??= ((float) $this->ask($io, $interactive, 'Closed department-hours in % (0 to below 100)', '5', $this->percent(...))) / 100.0;

        return new DemoHospitalRequest($new, $existingId, $annualVolume, $months, $closureShare, $slug, $existingData);
    }

    private function askName(SymfonyStyle $io): string
    {
        return (string) $io->ask('Hospital name', null, function (?string $value): string {
            $value = trim((string) $value);
            if ('' === $value) {
                throw new \InvalidArgumentException('The hospital needs a name.');
            }
            if ($this->wizard->hospitalNameTaken($value)) {
                throw new \InvalidArgumentException(\sprintf('A hospital named "%s" already exists.', $value));
            }

            return $value;
        });
    }

    private function askHospital(SymfonyStyle $io): Hospital
    {
        $byLabel = [];
        foreach ($this->wizard->existingHospitals() as $hospital) {
            $label = (string) $hospital->getName();
            if (isset($byLabel[$label])) {
                $label .= ' #'.(int) $hospital->getId();
            }
            $byLabel[$label] = $hospital;
        }
        if ([] === $byLabel) {
            throw new \DomainException('The demo database has no hospital yet. Install it with make demo-install or create a new hospital.');
        }

        $question = new Question(\sprintf('Hospital (%d in the demo database; type to search)', \count($byLabel)));
        $question->setAutocompleterValues(array_keys($byLabel));
        $question->setValidator(static fn (?string $value): Hospital => $byLabel[trim((string) $value)] ?? throw new \InvalidArgumentException('Choose a hospital from the list.'));

        $hospital = $io->askQuestion($question);
        \assert($hospital instanceof Hospital);

        return $hospital;
    }

    private function hospitalByPublicId(string $publicId): Hospital
    {
        if (!Uuid::isValid($publicId)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a hospital public id.', $publicId));
        }
        foreach ($this->wizard->existingHospitals() as $hospital) {
            if ($hospital->getPublicId()?->equals(Uuid::fromString($publicId))) {
                return $hospital;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Unknown hospital "%s".', $publicId));
    }

    private function dispatchArea(SymfonyStyle $io, bool $interactive, string $given): DispatchArea
    {
        $areas = [];
        foreach ($this->wizard->dispatchAreas() as $area) {
            $areas[(string) $area->getName()] = $area;
        }
        if ([] === $areas) {
            throw new \DomainException('The demo database has no dispatch area. Install it with make demo-install first.');
        }
        if ('' !== $given) {
            foreach ($areas as $label => $area) {
                if ($label === $given || (string) $area->getId() === $given) {
                    return $area;
                }
            }

            throw new \InvalidArgumentException(\sprintf('Unknown dispatch area "%s".', $given));
        }
        $labels = array_map(strval(...), array_keys($areas));

        return $areas[$this->choice($io, $interactive, 'Dispatch area', $labels, $labels[0])];
    }

    /**
     * @return array{0: string|null, 1: float|null, 2: float|null} prepared location slug, latitude, longitude
     */
    private function askLocation(SymfonyStyle $io, bool $allowCoordinates): array
    {
        $prepared = array_map(static fn (PreparedLocation $location): string => \sprintf('%s: %s', $location->slug, $location->label), $this->wizard->preparedLocations());
        $choices = [...$prepared, ...($allowCoordinates ? [self::ENTER_COORDINATES] : []), self::NO_LOCATION];

        $answer = (string) $io->choice('Location (prepared locations work offline, with isochrones)', $choices, $prepared[0] ?? self::NO_LOCATION);
        if (self::NO_LOCATION === $answer) {
            return [null, null, null];
        }
        if (self::ENTER_COORDINATES === $answer) {
            $latitude = (float) $io->ask('Latitude', null, $this->coordinate(90.0));
            $longitude = (float) $io->ask('Longitude', null, $this->coordinate(180.0));

            return [null, $latitude, $longitude];
        }

        return [strstr($answer, ':', true) ?: $answer, null, null];
    }

    /**
     * @param list<string> $choices
     */
    private function choice(SymfonyStyle $io, bool $interactive, string $question, array $choices, string $default): string
    {
        return $interactive ? (string) $io->choice($question, $choices, $default) : $default;
    }

    private function ask(SymfonyStyle $io, bool $interactive, string $question, string $default, callable $validator): string
    {
        return (string) ($interactive ? $io->ask($question, $default, $validator) : $validator($default));
    }

    private function renderHospital(SymfonyStyle $io, Hospital $hospital): void
    {
        $io->definitionList(
            ['Hospital' => (string) $hospital->getName()],
            ['Care tier, size, beds' => \sprintf('%s, %s, %d', $hospital->getTier()->value ?? '–', $hospital->getSize()->value ?? '–', (int) $hospital->getBeds())],
            ['Dispatch area' => (string) $hospital->getDispatchArea()?->getName()],
            ['Coordinates' => $this->coordinates($hospital->getLatitude(), $hospital->getLongitude()) ?? '–'],
            ['Owner' => $hospital->getOwner()?->getUserIdentifier() ?? '–'],
            ['Participating' => $hospital->isParticipating() ? 'yes' : 'no'],
        );
        $io->text('Its master data stays as it is; only the simulation is asked for.');
    }

    private function renderSummary(SymfonyStyle $io, DemoHospitalRequest $request): void
    {
        $period = $this->wizard->period($request->months);
        $new = $request->newHospital;
        $io->section('Summary');
        $io->definitionList(
            ['Environment' => \sprintf('%s, database "%s"', $this->environment, $this->database())],
            ['Hospital' => $new instanceof NewDemoHospital
                ? \sprintf('new: %s (%s care, %d beds → %s, %s)', $new->name, $new->tier->value, $new->beds, $new->size->value, strtolower($new->location->value))
                : \sprintf('existing hospital #%d (master data kept)', (int) $request->existingHospitalId)],
            ['Size by beds' => $this->bedBands()],
            ['Location' => match (true) {
                null !== $request->preparedLocation => \sprintf('prepared location "%s" unless the hospital has geodata', $request->preparedLocation),
                null !== $this->coordinates($new?->latitude, $new?->longitude) => $this->coordinates($new?->latitude, $new?->longitude).'; isochrones fetched online when possible',
                default => 'kept when present, else fetched online when possible (never a reason to stop)',
            }],
            ['Participant' => \sprintf('a new account "%s", password %s', $this->wizard->nextParticipantUsername(), DemoHospitalSetup::PASSWORD)],
            ['EMS allocations per year' => number_format($request->annualVolume)],
            ['Period' => \sprintf('%s to %s (%d months); %s stays free for the CSV upload', $period->from, $period->to, $request->months, $period->end()->format('Y-m'))],
            ['Closed department-hours' => \sprintf('%s %%', rtrim(rtrim(number_format($request->closureShare * 100.0, 1, '.', ''), '0'), '.'))],
            ['Existing generated data' => $request->existing->value],
        );
    }

    private function renderResult(SymfonyStyle $io, DemoProvisioning $provisioning, string $runId, ?GenerationRunStatus $status, ?int $allocations = null, ?int $closures = null, ?int $mciCases = null, bool $failed = false): void
    {
        $participant = $provisioning->participant;
        $period = $provisioning->plan->request->period;
        $io->newLine();
        $io->section('Result');
        $io->definitionList(
            ['Hospital' => \sprintf('%s (public id %s)', $provisioning->hospitalName, $provisioning->hospitalPublicId)],
            ['Owner' => (string) $provisioning->ownerName],
            ['Participant login' => $participant->username],
            ['Participant e-mail' => $participant->email],
            ['Participant password' => $participant->password],
            ['Dataset' => null === $allocations ? '–' : \sprintf('%s to %s: %s allocations, %s closure intervals, %d MCI case(s)', $period->from, $period->to, number_format($allocations), number_format((int) $closures), (int) $mciCases)],
            ['Generation run' => \sprintf('%s (%s)', $runId, $status instanceof GenerationRunStatus ? $status->value : '–')],
        );
        if ($failed) {
            $io->note([
                'The participant account and the hospital setup were kept: the login works, but the hospital has no new data.',
                'Months the run was replacing and had already deleted stay deleted; the retry generates every missing month.',
                \sprintf('Generate the hospital again without duplicates: %s app:generate:dataset --retry=%s', $this->console(), $runId),
                \sprintf('Details: %s app:generate:run:show %s -v', $this->console(), $runId),
            ]);

            return;
        }
        $io->note([
            'Every demo participant has this password. The account is synthetic (e-mail domain demo.invalid) and can open the hospital, its statistics and closure analytics.',
            'The statistics are rebuilt in the background (make demo-consume); you can leave the terminal and continue in the browser. The owner gets an in-app notification once everything is processed.',
            \sprintf('Follow the background stage: %s app:generate:run:show %s', $this->console(), $runId),
            \sprintf('Upload month for the demo CSV: %s app:demo:csv --hospital=%s', $this->console(), $provisioning->hospitalPublicId),
        ]);
    }

    private function database(): string
    {
        return (string) $this->connection->getDatabase();
    }

    /**
     * The console call of this environment, for the hints.
     */
    private function console(): string
    {
        return \sprintf('APP_ENV=%s php bin/console', $this->environment);
    }

    /**
     * The bed bands of the synthetic hospitals that give a new hospital its size, e.g. "Small 80–299, …".
     */
    private function bedBands(): string
    {
        $bands = [];
        foreach (HospitalFaker::BED_BANDS as $size => [$fewest, $most]) {
            $bands[] = \sprintf('%s %d–%d', $size, $fewest, $most);
        }

        return implode(', ', $bands).' (synthetic catalog; fewer beds count as Small, more as Large)';
    }

    /**
     * "latitude, longitude", or null without coordinates.
     */
    private function coordinates(?float $latitude, ?float $longitude): ?string
    {
        return null === $latitude || null === $longitude ? null : \sprintf('%s, %s', $latitude, $longitude);
    }

    private function tier(string $tier): HospitalTier
    {
        return HospitalTier::tryFrom(ucfirst(strtolower($tier))) ?? throw new \InvalidArgumentException('--tier takes basic, extended or full.');
    }

    /**
     * About 40 EMS allocations per bed and year, rounded to thousands: a starting point the presenter can change.
     */
    private function defaultVolume(int $beds): int
    {
        return max(1000, (int) round($beds * 40, -3));
    }

    private function positiveInt(?string $value): string
    {
        $value = trim((string) $value);
        if (1 !== preg_match('/^\d+$/', $value) || (int) $value <= 0) {
            throw new \InvalidArgumentException('Enter a whole number above 0.');
        }

        return $value;
    }

    private function percent(?string $value): string
    {
        $value = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($value) || (float) $value < 0.0 || (float) $value >= 100.0) {
            throw new \InvalidArgumentException('Enter a percentage from 0 to below 100.');
        }

        return $value;
    }

    private function coordinate(float $limit): \Closure
    {
        return static function (?string $value) use ($limit): string {
            $value = str_replace(',', '.', trim((string) $value));
            if (!is_numeric($value) || abs((float) $value) > $limit) {
                throw new \InvalidArgumentException(\sprintf('Enter a number from -%s to %s.', $limit, $limit));
            }

            return $value;
        };
    }
}
