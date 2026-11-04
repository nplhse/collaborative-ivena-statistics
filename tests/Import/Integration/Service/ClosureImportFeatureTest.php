<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\ClosureInterval;
use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Application\Factory\ClosureImporterFactory;
use App\Import\Application\Factory\RowReaderFactory;
use App\Import\Application\Mapping\ClosureHospitalGuard;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureAnalysisRebuilder;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRejectWriter;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ClosureImportFeatureTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testClosureFilePersistsIntervalsAndRejectsInvalidRows(): void
    {
        $user = UserFactory::createOne(['username' => 'closure-import-user']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Klinikum Beispiel',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        DepartmentFactory::createOne(['name' => 'Gastroenterologische Endoskopie']);
        DepartmentFactory::createOne(['name' => 'Handchirurgie']);

        $import = new Import()
            ->setName('Closure sample')
            ->setHospital($this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId()))
            ->setCreatedBy($this->em->getReference(\App\User\Domain\Entity\User::class, $user->getId()))
            ->setStatus(ImportStatus::PENDING)
            ->setType(ImportType::CLOSURE)
            ->setFilePath('closure_import_sample.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(100)
            ->setRowCount(0)
            ->setRunCount(0)
            ->setRunTime(0);
        $this->em->persist($import);
        $this->em->flush();

        $path = \dirname(__DIR__, 2).'/Fixtures/closure_import_sample.csv';
        $guard = new ClosureHospitalGuard();
        $scanReader = self::getContainer()->get(RowReaderFactory::class)->createFromCsvFile($path);
        $profile = $guard->profile(
            (int) $hospital->getId(),
            self::getContainer()->get(HospitalRepository::class)->findIdNameMap(),
            $guard->distinctShortNames($scanReader->rowsAssoc()),
        );
        $reader = self::getContainer()->get(RowReaderFactory::class)->createFromCsvFile($path);
        $rejectWriter = new InMemoryRejectWriter();
        $summary = self::getContainer()->get(ClosureImporterFactory::class)->create($reader, $rejectWriter)->import($import, $profile);

        self::assertSame(7, $summary->total);
        self::assertSame(4, $summary->ok);
        self::assertSame(3, $summary->rejected);

        $intervals = $this->em->getRepository(ClosureInterval::class)->findBy(['import' => $import->getId()]);
        self::assertCount(4, $intervals);

        $careLevels = array_map(static fn (ClosureInterval $interval): ?string => $interval->getCareLevel()?->value, $intervals);
        self::assertContains(ClosureCareLevel::OTHER->value, $careLevels);
        self::assertContains(ClosureCareLevel::EMERGENCY->value, $careLevels);

        $reasons = array_map(static fn (ClosureInterval $interval): ?string => $interval->getReason()?->value, $intervals);
        self::assertContains(ClosureReason::NOT_SPECIFIED->value, $reasons);
        self::assertContains(ClosureReason::NO_BED_CAPACITY->value, $reasons);

        $dst = array_values(array_filter(
            $intervals,
            static fn (ClosureInterval $interval): bool => 'Handchirurgie komplett' === $interval->getClosureUnit(),
        ));
        self::assertCount(1, $dst);
        self::assertSame(1270, $dst[0]->durationInMinutes());

        $units = array_map(static fn (ClosureInterval $interval): ?string => $interval->getClosureUnit(), $intervals);
        self::assertContains('COVID Normalstation', $units);
        self::assertContains(null, $units);

        $messages = implode("\n", array_map(
            static fn (array $record): string => implode(' | ', $record['messages']),
            $rejectWriter->all(),
        ));
        self::assertStringContainsString('UNKNOWN_REASON', $messages);
        self::assertStringContainsString('HOSPITAL_MISMATCH', $messages);
        self::assertStringContainsString('REF_NOT_FOUND', $messages);
    }

    /**
     * Export grouped by closure unit: records end with CRLF, list entries inside a cell are
     * separated by LF, and only those cells are quoted.
     *
     * Line 2 (A): 3 triples, valid                         → 3 rows stored
     * Line 3 (B): flat, valid, same start/end as A          → 1 row stored
     * Line 4 (C): lists of 2/2/1 entries                    → 1 row rejected
     * Line 5 (D): 2 triples, one unknown department         → 1 row stored, 1 rejected
     * Total 7 rows, 5 stored, 2 rejected.
     */
    public function testGroupedExportIsSplitIntoOneRowPerDepartmentAndCareLevel(): void
    {
        $user = UserFactory::createOne(['username' => 'closure-grouped-user']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Klinikum Beispiel',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        SpecialityFactory::createOne(['name' => 'Chirurgie']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        DepartmentFactory::createOne(['name' => 'Gastroenterologische Endoskopie']);
        DepartmentFactory::createOne(['name' => 'Handchirurgie']);

        $header = 'KHS-Versorgungsgebiet;Krankenhaus-Kurzname;Schließungseinheit;Fachgebiet;Fachbereich;Behandlungsdringlichkeit;'
            .'Datum (Schließungs-Beginn);Uhrzeit (Schließungs-Beginn);Schließungs-Dauer (Minuten);Datum (Schließungs-Ende);'
            .'Uhrzeit (Schließungs-Ende);Grund;Gruppen-Schließungs-ID;Bemerkung;Krankenhausinterne Bemerkung;Eingetragen am;Geändert am;Typ';
        $records = [
            "1;Klinikum Beispiel;ITS abmelden;\"Innere Medizin\nInnere Medizin \nChirurgie\";\"Kardiologie\nKardiologie\nHandchirurgie\";"
                ."\"Notfallversorgung\nStationäre Versorgung\nNotfallversorgung\";01.01.2026;09:00:00;1440;02.01.2026;09:00:00;"
                ."keine Bettenkapazitäten;;\"Notiz\nzweite Zeile\";;01.01.2026 08:10;01.01.2026 08:10;Klinik",
            '1;Klinikum Beispiel;Schockraum abmelden;Innere Medizin;Gastroenterologische Endoskopie;Notfallversorgung;'
                .'01.01.2026;09:00:00;1440;02.01.2026;09:00:00;k.A.;;;;01.01.2026 08:12;01.01.2026 08:12;Klinik',
            "1;Klinikum Beispiel;Isolation;\"Innere Medizin\nChirurgie\";\"Kardiologie\nHandchirurgie\";Notfallversorgung;"
                .'02.01.2026;10:00:00;60;02.01.2026;11:00:00;k.A.;;;;02.01.2026 09:00;02.01.2026 09:00;Klinik',
            "1;Klinikum Beispiel;Diagnostik;\"Innere Medizin\nInnere Medizin\";\"Kardiologie\nNeuroradiologie\";"
                ."\"Notfallversorgung\nNotfallversorgung\";03.01.2026;10:00:00;90;03.01.2026;11:30:00;OP-Meldung;;;;"
                .'03.01.2026 09:00;03.01.2026 09:00;Klinik',
        ];
        $path = tempnam(sys_get_temp_dir(), 'ivena_closure_grouped_');
        self::assertNotFalse($path);
        file_put_contents($path, $header."\r\n".implode("\r\n", $records)."\r\n");

        try {
            $import = new Import()
                ->setName('Closure grouped')
                ->setHospital($this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId()))
                ->setCreatedBy($this->em->getReference(\App\User\Domain\Entity\User::class, $user->getId()))
                ->setStatus(ImportStatus::PENDING)
                ->setType(ImportType::CLOSURE)
                ->setFilePath('closure_grouped.csv')
                ->setFileExtension('csv')
                ->setFileMimeType('text/csv')
                ->setFileSize(100)
                ->setRowCount(0)
                ->setRunCount(0)
                ->setRunTime(0);
            $this->em->persist($import);
            $this->em->flush();

            $guard = new ClosureHospitalGuard();
            $readers = self::getContainer()->get(RowReaderFactory::class);
            $profile = $guard->profile(
                (int) $hospital->getId(),
                self::getContainer()->get(HospitalRepository::class)->findIdNameMap(),
                $guard->distinctShortNames($readers->createFromCsvFile($path)->rowsAssoc()),
            );
            $rejectWriter = new InMemoryRejectWriter();
            $summary = self::getContainer()->get(ClosureImporterFactory::class)
                ->create($readers->createFromCsvFile($path), $rejectWriter)
                ->import($import, $profile);
        } finally {
            @unlink($path);
        }

        self::assertSame(7, $summary->total);
        self::assertSame(5, $summary->ok);
        self::assertSame(2, $summary->rejected);

        $intervals = $this->em->getRepository(ClosureInterval::class)->findBy(['import' => $import->getId()], ['id' => 'ASC']);
        self::assertSame([
            ['ITS abmelden', 'Innere Medizin', 'Kardiologie', ClosureCareLevel::EMERGENCY->value],
            ['ITS abmelden', 'Innere Medizin', 'Kardiologie', ClosureCareLevel::INPATIENT->value],
            ['ITS abmelden', 'Chirurgie', 'Handchirurgie', ClosureCareLevel::EMERGENCY->value],
            ['Schockraum abmelden', 'Innere Medizin', 'Gastroenterologische Endoskopie', ClosureCareLevel::EMERGENCY->value],
            ['Diagnostik', 'Innere Medizin', 'Kardiologie', ClosureCareLevel::EMERGENCY->value],
        ], array_map(static fn (ClosureInterval $interval): array => [
            $interval->getClosureUnit(),
            $interval->getSpeciality()?->getName(),
            $interval->getDepartment()?->getName(),
            $interval->getCareLevel()?->value,
        ], $intervals));
        self::assertSame("Notiz\nzweite Zeile", $intervals[0]->getRemark());

        $rejects = $rejectWriter->all();
        self::assertSame([4, 5], array_column($rejects, 'line'));
        self::assertStringContainsString('GROUPED_LIST_MISMATCH', implode(' | ', $rejects[0]['messages']));
        self::assertStringContainsString('REF_NOT_FOUND', implode(' | ', $rejects[1]['messages']));
        self::assertSame('Neuroradiologie', $rejects[1]['row']['fachbereich']);

        // A and B share start and end without an IVENA group id: one cluster event.
        // The importer clears the entity manager, so set the status directly.
        $this->em->getConnection()->executeStatement(
            'UPDATE import SET status = ? WHERE id = ?',
            [ImportStatus::COMPLETED->value, $import->getId()],
        );
        self::getContainer()->get(ClosureAnalysisRebuilder::class)->rebuild([(int) $hospital->getId()]);
        self::assertSame(
            ['cluster', 'single'],
            $this->em->getConnection()->fetchFirstColumn(
                'SELECT event_type FROM closure_event WHERE hospital_id = ? ORDER BY event_type',
                [$hospital->getId()],
            ),
        );
    }
}
