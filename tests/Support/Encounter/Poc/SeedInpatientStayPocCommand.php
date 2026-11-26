<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Poc;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Encounter\Application\Import\ImportEncountersDispatcher;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EffectiveAssignments;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterMatch;
use Cois\Plugin\EdVisit\Domain\Enum\MatchStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Seeds an empty `test` database for the browser check of #1274 (review "Try it in the browser"): hospital H,
 * the users of InpatientStayPoc, allocations X and Y, the ED visits A, B and C through the real importer, and
 * the links A → X and B → Y. Refuses a database that already holds application data. Test values only.
 */
#[AsCommand(name: 'app:poc:seed-inpatient-stay', description: 'Seed an empty test database for the browser check of the inpatient_stay plugin (#1274).')]
final readonly class SeedInpatientStayPocCommand
{
    /** Password of every seeded user; test value of this command. */
    public const string PASSWORD = 'poc-password';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportEncountersDispatcher $dispatcher,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Also import the inpatient_stay CSV through the importer, as the upload form would (to check only the views).')]
        bool $withStays = false,
    ): int {
        $connection = $this->entityManager->getConnection();
        $io->writeln(sprintf('Database: <info>%s</info>', (string) $connection->fetchOne('SELECT current_database()')));
        $existing = (int) $connection->fetchOne('SELECT (SELECT COUNT(*) FROM "user") + (SELECT COUNT(*) FROM hospital) + (SELECT COUNT(*) FROM import)');
        if ($existing > 0) {
            $io->error('The database already holds application data (users, hospitals or imports). Seed a freshly migrated database only.');

            return Command::FAILURE;
        }

        $reviewer = $this->user('poc-reviewer');
        $viewer = $this->user('poc-viewer');
        $withoutGrant = $this->user('poc-nogrant');
        $state = StateFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'name' => 'PoC Hospital',
            'owner' => UserFactory::createOne(),
            'createdBy' => UserFactory::createOne(),
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);
        // Encounter grants are given only to people with access to the hospital (ADR 028).
        foreach ([$reviewer, $viewer] as $member) {
            HospitalAccessGrantFactory::createOne([
                'hospital' => $hospital,
                'user' => $member,
                'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
                'createdBy' => $hospital->getOwner(),
            ]);
        }
        $this->grant($hospital, $reviewer, [EncounterGrantAction::Upload, EncounterGrantAction::Review]);
        $this->grant($hospital, $viewer, [EncounterGrantAction::ViewCases]);

        $allocations = ImportFactory::createOne(['name' => 'PoC allocations', 'hospital' => $hospital, 'createdBy' => $reviewer, 'type' => ImportType::ALLOCATION, 'status' => ImportStatus::COMPLETED, 'filePath' => 'dummy/path']);
        $allocationX = $this->allocation($hospital, $allocations, '08:30');
        $allocationY = $this->allocation($hospital, $allocations, '09:30');

        $edImportId = $this->importFile($hospital, $reviewer, 'PoC ED visits', 'ed_visit', InpatientStayPoc::edVisits(), 'var/imports/poc/ed_visits.csv');
        $visits = $this->visitIds($edImportId);
        $this->link($allocationX, $visits['A']);
        $this->link($allocationY, $visits['B']);
        $stayImportId = $withStays
            ? $this->importFile($hospital, $reviewer, 'PoC inpatient stays', InpatientStayDimension::SCHEMA, InpatientStayPoc::stays(), 'var/imports/poc/inpatient_stays.csv')
            : null;

        $io->success('Seeded the browser check of the inpatient_stay plugin.');
        $io->definitionList(
            ['Logins (password '.self::PASSWORD.')' => 'poc-reviewer (hospital access, encounter upload and review), poc-viewer (hospital access, view cases), poc-nogrant (beta role, no access)'],
            ['CSV to upload' => InpatientStayPoc::CSV],
            ['Upload' => '/import/new'],
            ['ED import (try deleting it)' => '/import/'.$edImportId],
            ['inpatient_stay import' => null === $stayImportId ? 'upload the CSV at /import/new' : '/import/'.$stayImportId],
            ['Case file of allocation X (stay expected)' => '/explore/allocation/'.$allocationX->getPublicId()],
            ['Case file of allocation Y (no stay)' => '/explore/allocation/'.$allocationY->getPublicId()],
            ['Explore' => '/explore'],
            ['Stays of the hospital' => '/explore/inpatient-stays/'.$hospital->getId()],
            ['Assignment conflicts' => '/tools/encounter-assignments/'.$hospital->getId()],
        );

        return Command::SUCCESS;
    }

    private function user(string $username): User
    {
        return UserFactory::createOne([
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => self::PASSWORD,
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA'],
        ]);
    }

    /**
     * @param list<EncounterGrantAction> $actions
     */
    private function grant(Hospital $hospital, User $user, array $actions): void
    {
        $this->entityManager->persist(new EncounterAccessGrant(
            $this->entityManager->getReference(Hospital::class, (int) $hospital->getId()),
            $this->entityManager->getReference(User::class, (int) $user->getId()),
            $actions,
        ));
        $this->entityManager->flush();
    }

    private function allocation(Hospital $hospital, Import $import, string $arrival): Allocation
    {
        $arrivalAt = new \DateTimeImmutable('2026-10-12 '.$arrival);

        return AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $hospital->getDispatchArea(),
            'state' => $hospital->getState(),
            'createdAt' => $arrivalAt->modify('-30 minutes'),
            'arrivalAt' => $arrivalAt,
            'gender' => AllocationGender::FEMALE,
            'age' => 70,
            'isWithPhysician' => false,
            'isWorkAccident' => false,
            'speciality' => SpecialityFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'assignment' => AssignmentFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => null,
            'infection' => null,
            'occasion' => null,
            'secondaryTransport' => null,
            'assessment' => null,
        ]);
    }

    private function importFile(Hospital $hospital, User $creator, string $name, string $schema, string $content, string $storedPath): int
    {
        new Filesystem()->dumpFile($this->projectDir.'/'.$storedPath, $content);

        $import = new Import()
            ->setName($name)
            ->setHospital($this->entityManager->getReference(Hospital::class, (int) $hospital->getId()))
            ->setCreatedBy($this->entityManager->getReference(User::class, (int) $creator->getId()))
            ->setType(ImportType::ENCOUNTER)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($storedPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(\strlen($content))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);
        $this->entityManager->persist($import);
        $this->entityManager->persist(new EncounterImport($import, $schema, 1, $this->entityManager->getReference(User::class, (int) $creator->getId()), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $importId = (int) $import->getId();
        $this->dispatcher->dispatch($importId, ImportRunTrigger::Upload, (int) $creator->getId());
        $this->entityManager->clear();

        return $importId;
    }

    /**
     * @return array<string, int>
     */
    private function visitIds(int $importId): array
    {
        $ids = [];
        foreach (['A', 'B', 'C'] as $label) {
            $ids[$label] = (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT encounter_id FROM ed_visit WHERE import_id = ? AND visit_fingerprint = ?',
                [$importId, EdVisitFileBuilder::fingerprint($label)],
            );
        }

        return $ids;
    }

    private function link(Allocation $allocation, int $encounterId): void
    {
        $encounter = $this->entityManager->find(Encounter::class, $encounterId);
        $allocation = $this->entityManager->find(Allocation::class, $allocation->getId());
        if (!$encounter instanceof Encounter || !$allocation instanceof Allocation) {
            throw new \LogicException('Seeded visit or allocation is missing.');
        }

        $this->entityManager->persist(new EncounterMatch($allocation, $encounter, MatchStatus::AutoAccepted, AnchorSource::Assessment, 7, new \DateTimeImmutable('2026-10-12 09:00'), 0.9697, 9, ['time' => 4], 1, '1.0.0'));
        $this->entityManager->flush();
        EffectiveAssignments::syncFromMatches($this->entityManager->getConnection());
    }
}
