<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\DataPrep;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The shipped data preparation tool with two schema contributions (#1301): the HTML file a beta user downloads is
 * run offline (tests/Encounter/DataPrep/run-shipped-tool.js: its own inline scripts, its bundled catalogs, no
 * network) once with the ORBIS MTS sample and once with the inpatient_stay PoC, and both exchange files go through
 * the regular encounter import. The ORBIS rows and fingerprints are byte-identical to the reference file of the
 * tool core (orbis-mts-sample.ed_visit.csv); the manifest carries the tool's own version.
 */
#[ResetDatabase]
final class ShippedDataPrepToolTest extends WebTestCase
{
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private const string FIXTURES = __DIR__.'/../../DataPrep/fixtures';

    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/shipped-data-prep-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testTheDownloadedToolWritesOrbisAndInpatientStayFilesOfflineThatTheImportAccepts(): void
    {
        $node = new ExecutableFinder()->find('node');
        if (null === $node) {
            self::markTestSkipped('Node.js is needed to run the downloaded tool.');
        }

        $client = self::createClient();
        $uploader = $this->user();
        $hospital = $this->hospital($uploader);
        $this->encounterGrant($hospital, $uploader, [EncounterGrantAction::Upload]);
        foreach (['Innere Medizin', 'Unfallchirurgie'] as $name) {
            DepartmentFactory::createOne(['name' => $name, 'createdBy' => $uploader]);
        }
        $html = $this->download($client, $uploader);

        /** @var array{key: string, keyCheck: string} $vectors */
        $vectors = json_decode((string) file_get_contents(self::FIXTURES.'/fingerprint-vectors.json'), true, 512, \JSON_THROW_ON_ERROR);
        file_put_contents($this->directory.'/key.json', json_encode(['format' => 'encounter-key', 'version' => 1, 'label' => 'Test', 'created' => '2026-11-12', 'key' => $vectors['key'], 'check' => $vectors['keyCheck']], \JSON_THROW_ON_ERROR));

        $orbis = $this->runTool($node, $html, self::FIXTURES.'/orbis-mts-sample.csv', 'orbis.csv', self::FIXTURES.'/local-map.sample.json');
        self::assertSame(['profile' => 'orbis-mts', 'schema' => 'ed_visit', 'rows' => 6, 'scripts' => 3, 'external' => false, 'catalogs' => ['mts-de']], array_intersect_key($orbis['summary'], array_flip(['profile', 'schema', 'rows', 'scripts', 'external', 'catalogs'])));
        self::assertSame("default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src http://localhost/data-prep/latest.json http://localhost/data-prep/mts-catalog.json; base-uri 'none'; form-action 'none'", str_replace('&#039;', "'", $orbis['summary']['csp']));
        $reference = (string) file_get_contents(self::FIXTURES.'/orbis-mts-sample.ed_visit.csv');
        self::assertSame($reference, $orbis['file'], 'ORBIS rows, fingerprints and manifest equal the tool core\'s reference file');
        self::assertStringContainsString(';tool='.$this->toolVersion().';', strtok($orbis['file'], "\n") ?: '');

        $stays = $this->runTool($node, $html, \dirname(__DIR__, 3).'/Fixtures/Plugin/InpatientStay/fixtures/inpatient_stay_poc.source.csv', 'stays.csv');
        self::assertSame(['profile' => 'inpatient-poc', 'schema' => 'inpatient_stay', 'rows' => 3], array_intersect_key($stays['summary'], array_flip(['profile', 'schema', 'rows'])));
        self::assertStringStartsWith('#encounter-exchange;schema=inpatient_stay;schema_version=1;profile=inpatient-poc;profile_version=1;tool='.$this->toolVersion().';key_check='.$vectors['keyCheck'].';fingerprint=inpatient-stay:v1;rows=3', $stays['file']);
        $lines = explode("\n", trim($stays['file']));
        self::assertSame('stay_fingerprint;case_key;admitted_at;discharge_diagnosis;discharged_at', $lines[1]);
        self::assertStringNotContainsString('Testperson', $stays['file']);
        self::assertStringNotContainsString('01.02.1950', $stays['file']);
        // Same case number, same key: the stay has the case key of the ED visit (vector 1 of fingerprint-vectors.json).
        self::assertStringContainsString(';2bf3f27f2fc648f1fa7a509494a18f4d38bb2408aa15015865c5466f610d1ded;2026-10-05T10:05;I21.4;2026-10-12T11:00', $stays['file']);

        // Both files through the regular encounter import.
        $edImport = $this->uploadEncounterFile($hospital, $uploader, $orbis['file'], 'shipped tool ORBIS');
        $stayImport = $this->uploadEncounterFile($hospital, $uploader, $stays['file'], 'shipped tool stays', schema: InpatientStayDimension::SCHEMA);
        foreach ([$edImport => 6, $stayImport => 3] as $importId => $inserted) {
            $encounterImport = $this->encounterImportOf($importId);
            self::assertNull($encounterImport->getFileRejection());
            self::assertSame([$inserted, 0], [$encounterImport->getInserted(), $encounterImport->getRejected()]);
            $import = $this->entityManager()->find(Import::class, $importId);
            self::assertInstanceOf(Import::class, $import);
            self::assertSame(ImportStatus::COMPLETED, $import->getStatus());
            self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$importId]));
        }
    }

    private function download(KernelBrowser $client, User $user): string
    {
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/tools/data-prep/download');
        self::assertResponseIsSuccessful();
        $path = $this->directory.'/tool.html';
        file_put_contents($path, (string) $client->getResponse()->getContent());

        return $path;
    }

    /**
     * @return array{file: string, summary: array<string, mixed>}
     */
    private function runTool(string $node, string $html, string $source, string $out, ?string $localMap = null): array
    {
        $process = new Process(array_values(array_filter([$node, \dirname(__DIR__, 2).'/DataPrep/run-shipped-tool.js', $html, $source, $this->directory.'/key.json', $this->directory.'/'.$out, $localMap])));
        $process->mustRun();
        /** @var array<string, mixed> $summary */
        $summary = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);

        return ['file' => (string) file_get_contents($this->directory.'/'.$out), 'summary' => $summary];
    }

    private function toolVersion(): string
    {
        $version = self::getContainer()->getParameter('app.encounter.data_prep.version');
        self::assertIsString($version);

        return $version;
    }
}
