<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * End-to-end contract of the data preparation tool and the import (#1052): the file the tool core
 * writes for the synthetic ORBIS-like sample imports without file or row rejects.
 *
 * The file is produced by `tests/Encounter/DataPrep/exchange-fixture.test.js` (`npm run test:js`), which
 * regenerates it whenever the tool output changes; it is never edited by hand. The sample has eight
 * rows; the tool skips two (no case number, no time anchor) and writes six visits of five cases, one
 * case with two visits.
 */
#[ResetDatabase]
final class DataPrepToolOutputImportTest extends KernelTestCase
{
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private const string FILE = __DIR__.'/../../../DataPrep/fixtures/orbis-mts-sample.ed_visit.csv';

    private Hospital $hospital;

    private User $uploader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
        // The departments the sample's local map points to (local-map.sample.json).
        foreach (['Innere Medizin', 'Unfallchirurgie'] as $name) {
            DepartmentFactory::createOne(['name' => $name, 'createdBy' => $this->uploader]);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testToolOutputForTheSyntheticSampleImportsWithoutRejects(): void
    {
        $content = (string) file_get_contents(self::FILE);
        self::assertStringStartsWith('#encounter-exchange;schema=ed_visit;schema_version=1;profile=orbis-mts;', $content);

        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, $content, 'tool output');

        $encounterImport = $this->encounterImportOf($importId);
        self::assertNull($encounterImport->getFileRejection());
        self::assertSame(
            ['inserted' => 6, 'duplicates' => 0, 'skipped' => 0, 'rejected' => 0, 'suspected' => 0],
            [
                'inserted' => $encounterImport->getInserted(),
                'duplicates' => $encounterImport->getDuplicates(),
                'skipped' => $encounterImport->getSkipped(),
                'rejected' => $encounterImport->getRejected(),
                'suspected' => $encounterImport->getSuspectedDuplicates(),
            ],
        );
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);
        self::assertSame(ImportStatus::COMPLETED, $import->getStatus());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$importId]));
        self::assertSame(
            ['Innere Medizin', 'Unfallchirurgie'],
            $this->connection()->fetchFirstColumn('SELECT d.name FROM encounter e JOIN department d ON d.id = e.admitting_department_id WHERE e.import_id = ? ORDER BY d.name', [$importId]),
        );

        // Uploading the same output again only finds known visits.
        $again = $this->uploadEncounterFile($this->hospital, $this->uploader, $content, 'tool output again');
        self::assertSame(6, $this->encounterImportOf($again)->getDuplicates());
        self::assertSame(0, $this->encounterImportOf($again)->getInserted());
    }
}
