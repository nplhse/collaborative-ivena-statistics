<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Demo;

use App\Allocation\Application\Contracts\PreparedLocationStoreInterface;
use App\Allocation\Application\Hospital\PreparedLocation\ApplyPreparedLocationReport;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocationApplier;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Generator\Application\Dataset\DatasetGenerator;
use App\DataFixtures\Generator\Application\Dataset\DatasetPeriod;
use App\DataFixtures\Generator\Application\Dataset\DatasetProgress;
use App\DataFixtures\Generator\Application\Dataset\DatasetRequest;
use App\DataFixtures\Generator\Application\Dataset\DatasetResult;
use App\DataFixtures\Generator\Application\ProfileSelection;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Generator\Application\Run\GenerationRunTrigger;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The demo wizard (#791 step 4) without its console: sets up a hospital for the presentation and generates its
 * synthetic dataset through the engine of #877. Two entry points, one path:
 *
 * 1. provision(): in one transaction the participant account, the new hospital (or what an existing one lacks)
 *    and the dataset plan. A plan that cannot run rolls everything back, so a refused wizard leaves no account.
 * 2. applyLocation(): the prepared location, offline, after the hospital exists (never forced over coordinates).
 * 3. start() and generate(): the generation run (trigger wizard, owner notified); the projections continue in the
 *    Messenger worker.
 */
final readonly class DemoHospitalWizard
{
    public function __construct(
        private DemoHospitalSetup $setup,
        private DatasetGenerator $datasets,
        private PreparedLocationStoreInterface $locations,
        private PreparedLocationApplier $applier,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ClockInterface $clock,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * The demo window: whole months up to the month before last, so the last complete month stays for the CSV upload.
     */
    public function period(int $months): DatasetPeriod
    {
        return DatasetPeriod::demoDefault($this->clock, $months);
    }

    /**
     * @return list<Hospital> hospitals of the demo database, by name
     */
    public function existingHospitals(): array
    {
        return $this->entityManager->getRepository(Hospital::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<DispatchArea>
     */
    public function dispatchAreas(): array
    {
        return $this->entityManager->getRepository(DispatchArea::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<PreparedLocation>
     */
    public function preparedLocations(): array
    {
        return $this->locations->all();
    }

    public function hospitalNameTaken(string $name): bool
    {
        return $this->setup->nameTaken($name);
    }

    /**
     * @throws DemoWizardRefused when the dataset cannot be generated; nothing is written then
     */
    public function provision(DemoHospitalRequest $request): DemoProvisioning
    {
        if (!\in_array($this->environment, DatasetGenerator::ALLOWED_ENVIRONMENTS, true)) {
            throw new DemoWizardRefused([\sprintf('The demo wizard runs only in %s, not in "%s".', implode(', ', DatasetGenerator::ALLOWED_ENVIRONMENTS), $this->environment)]);
        }
        if (null !== $request->preparedLocation && !$this->locations->exists($request->preparedLocation)) {
            throw new DemoWizardRefused([\sprintf('Unknown prepared location "%s". List them with app:geo:list-prepared-locations.', $request->preparedLocation)]);
        }
        $period = $this->period($request->months);

        $this->connection->beginTransaction();
        try {
            $existing = null;
            if (null !== $request->existingHospitalId) {
                $existing = $this->entityManager->find(Hospital::class, $request->existingHospitalId)
                    ?? throw new DemoWizardRefused([\sprintf('Unknown hospital #%d.', $request->existingHospitalId)]);
                $this->refuseLocationConflict($existing, $request->preparedLocation);
            }
            $name = $existing?->getName() ?? $request->newHospital->name ?? '';

            $participant = $this->setup->createParticipant($name);
            $user = $this->entityManager->find(User::class, $participant->userId) ?? throw new \LogicException('The participant was not stored.');
            $changes = [];
            if ($request->newHospital instanceof NewDemoHospital) {
                $hospital = $this->setup->createHospital($request->newHospital, $user, $period->firstDay());
            } else {
                $hospital = $existing ?? throw new \LogicException('No hospital chosen.');
                $changes = $this->setup->prepareExisting($hospital, $user, $period->firstDay());
            }
            $this->entityManager->flush();

            $hospitalId = (int) $hospital->getId();
            $owner = $hospital->getOwner();
            $provisioning = new DemoProvisioning(
                request: $request,
                participant: $participant,
                hospitalId: $hospitalId,
                hospitalName: (string) $hospital->getName(),
                hospitalPublicId: (string) $hospital->getPublicId(),
                ownerName: $owner?->getUserIdentifier(),
                changes: $changes,
                plan: $this->datasets->prepare(new DatasetRequest(
                    scope: GenerationRunScope::One,
                    hospitalIds: [$hospitalId],
                    period: $period,
                    existing: $request->existing,
                    selections: [$hospitalId => new ProfileSelection(annualVolume: $request->annualVolume, closureShare: $request->closureShare)],
                    trigger: GenerationRunTrigger::Wizard,
                    notify: true,
                    initiatedById: $participant->userId,
                    // The owner of an existing hospital stays; the presenter's account is told as well.
                    notifyUserIds: [$participant->userId],
                )),
            );

            $blockers = $provisioning->plan->runBlockers();
            if ([] !== $blockers) {
                throw new DemoWizardRefused($blockers);
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->entityManager->clear();

            throw $exception;
        }
        $this->entityManager->clear();

        return $provisioning;
    }

    /**
     * Applies the prepared location of the request, if any; null when there is none.
     */
    public function applyLocation(DemoProvisioning $provisioning): ?ApplyPreparedLocationReport
    {
        $slug = $provisioning->request->preparedLocation;
        if (null === $slug) {
            return null;
        }
        $hospital = $this->entityManager->find(Hospital::class, $provisioning->hospitalId) ?? throw new \DomainException('The hospital was deleted.');
        $report = $this->applier->run($slug, $hospital, apply: true, force: false);
        $this->entityManager->clear();

        return $report;
    }

    /**
     * Records the generation run; its id is known before the long part starts.
     */
    public function start(DemoProvisioning $provisioning): string
    {
        return $this->datasets->start($provisioning->plan);
    }

    public function generate(string $runId, ?DatasetProgress $progress = null): DatasetResult
    {
        return $this->datasets->generate($runId, $progress);
    }

    /**
     * An existing hospital keeps its coordinates: a prepared location for other coordinates is refused up front.
     */
    private function refuseLocationConflict(Hospital $hospital, ?string $slug): void
    {
        if (null === $slug) {
            return;
        }
        $check = $this->applier->run($slug, $hospital, apply: false, force: false);
        if (!$check->success) {
            throw new DemoWizardRefused([\sprintf('The prepared location "%s" cannot be applied: %s The wizard never replaces coordinates of an existing hospital.', $slug, (string) $check->error)]);
        }
    }
}
