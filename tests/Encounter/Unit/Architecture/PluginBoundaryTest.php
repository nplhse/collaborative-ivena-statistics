<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Encounter schema plugins use only the plugin API of the application (ADR 037, extension-points.md), and the
 * application knows no plugin (test bundles and `plugins/`). Deptrac reports the same rule (layer Encounter_Plugin)
 * but does not fail CI, this test does.
 */
final class PluginBoundaryTest extends TestCase
{
    /** Test bundles and the source of the shipped plugins. */
    private const array PLUGINS = ['/tests/Fixtures/Plugin', '/plugins/ed-visit/src'];

    /**
     * What a plugin may import from the application (#1300): the Encounter contract, the few Encounter classes
     * a schema needs to reference (the encounter entity for its Doctrine relation, the voter attributes, the
     * base-data enums, the grant repository, the department catalog, the import event), the entities and
     * enums of other contexts, and their documented extension points, among them the Statistics page API for a
     * plugin's statistics page (filter, period and analysis context, time series, query keys) and the fixture API
     * for its synthetic data.
     */
    private const array ALLOWED = [
        'App\\Encounter\\Contract\\',
        \App\Encounter\Domain\Entity\Encounter::class,
        \App\Encounter\Application\Security\EncounterAttribute::class,
        \App\Encounter\Domain\Enum\AnchorSource::class,
        \App\Encounter\Domain\Enum\CaseStatus::class,
        \App\Encounter\Domain\Enum\EncounterGrantAction::class,
        \App\Encounter\Domain\Enum\EncounterInsertOutcome::class,
        \App\Encounter\Domain\Enum\Sex::class,
        \App\Encounter\Application\Contract\DepartmentCatalogInterface::class,
        \App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface::class,
        \App\Encounter\Application\Event\EncountersImported::class,
        'App\\Allocation\\Domain\\Entity\\',
        'App\\Allocation\\Domain\\Enum\\',
        'App\\Allocation\\Application\\Explore\\',
        'App\\Allocation\\Application\\Hospital\\MyHospitals\\Action\\',
        'App\\Import\\Domain\\Entity\\',
        'App\\Import\\Domain\\Enum\\',
        \App\Import\Application\Event\ImportCompleted::class,
        \App\Import\Application\Contracts\ImportDataRemoverInterface::class,
        'App\\User\\Domain\\Entity\\',
        \App\User\Domain\Security\UserRole::class,
        'App\\Shared\\Application\\Navigation\\',
        'App\\Shared\\Application\\DataTable\\',
        'App\\Shared\\UI\\Twig\\DataTable\\',
        \App\Shared\UI\Http\DataTablePreferenceQueryState::class,
        'App\\Shared\\Infrastructure\\Audit\\',
        \App\Allocation\Application\Contracts\HospitalLookupInterface::class,
        \App\Statistics\Application\DTO\StatisticsFilter::class,
        \App\Statistics\Application\DTO\StatisticsFilterScope::class,
        \App\Statistics\Application\StatisticsPeriodResolver::class,
        \App\Statistics\Application\TimeSeries\TimeSeriesAxisFiller::class,
        \App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver::class,
        \App\Statistics\UI\Http\Controller\AnalysisContextScopeMode::class,
        \App\Statistics\UI\Http\Controller\AnalysisContextViewModelFactory::class,
        \App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory::class,
        \App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver::class,
        \App\Statistics\UI\Http\Navigation\StatisticsQueryKeys::class,
        // Fixture API for a plugin's synthetic data (dev, test and demo only, #1300).
        \App\DataFixtures\Allocation\FixtureHospitals::class,
        \App\DataFixtures\Allocation\PatternAllocationFixture::class,
        \App\DataFixtures\FixtureVolumeResolver::class,
        \App\DataFixtures\Generator\Application\ImportBatchPlanner::class,
        \App\DataFixtures\Generator\Infrastructure\Random\RandomStreams::class,
        \App\DataFixtures\Purger\FixturePurgeParticipantInterface::class,
        \App\Encounter\Domain\Entity\EncounterAccessGrant::class,
        \App\Encounter\Domain\Entity\EncounterImport::class,
        \App\Import\Domain\ValueObject\ImportDataCoverage::class,
        'App\\Tests\\Fixtures\\Plugin\\',
    ];

    /**
     * Application code that still names the ED visit plugin while #1300 moves it: the ED parts of the data
     * preparation tool (bundled MTS catalog, local map values; #1301 and step 4h).
     */
    private const array NOT_YET_MOVED = [
        '/src/Encounter/UI/DataPrep/DataPrepToolRenderer.php',
        '/src/Encounter/UI/Http/Controller/DataPrep/ShowDataPrepLocalMapController.php',
    ];

    public function testPluginsImportOnlyThePluginApiOfTheApplication(): void
    {
        $violations = [];
        $files = array_merge(...array_map($this->phpFiles(...), self::PLUGINS));
        foreach ($files as $file) {
            preg_match_all('/^use (App\\\\[^;\s]+)/m', (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $import) {
                if (!$this->allowed($import)) {
                    $violations[] = substr($file, \strlen($this->root())).': '.$import;
                }
            }
        }

        foreach (self::PLUGINS as $directory) {
            self::assertNotEmpty($this->phpFiles($directory), $directory);
        }
        self::assertSame([], $violations);
    }

    public function testTheApplicationNamesNoPlugin(): void
    {
        $violations = [];
        foreach ($this->phpFiles('/src') as $file) {
            if (\in_array(substr($file, \strlen($this->root())), self::NOT_YET_MOVED, true)) {
                continue;
            }
            // Class and namespace references; the schema name `inpatient_stay` may appear in docs as an example.
            if (1 === preg_match('/InpatientStay|Fixtures\\\\Plugin|Cois\\\\Plugin/', (string) file_get_contents($file))) {
                $violations[] = substr($file, \strlen($this->root()));
            }
        }

        self::assertSame([], $violations);
    }

    public function testThePatternFindsAForbiddenImport(): void
    {
        self::assertSame(1, preg_match_all('/^use (App\\\\[^;\s]+)/m', "use App\\Allocation\\Domain\\Entity\\Hospital;\n"));
    }

    public function testTheAllowListAcceptsExactNamesAndPrefixesOnly(): void
    {
        self::assertTrue($this->allowed(\App\Encounter\Contract\Import\SchemaWriteResult::class));
        self::assertTrue($this->allowed(\App\Encounter\Domain\Entity\Encounter::class));
        self::assertFalse($this->allowed(\App\Encounter\Domain\Entity\EncounterKeyState::class));
        self::assertFalse($this->allowed(\App\Encounter\Infrastructure\Assignment\EncounterAssignments::class));
    }

    public function testTheNotYetMovedFilesStillExist(): void
    {
        foreach (self::NOT_YET_MOVED as $file) {
            self::assertFileExists($this->root().$file, 'Remove moved files from NOT_YET_MOVED.');
        }
    }

    private function allowed(string $import): bool
    {
        return array_any(self::ALLOWED, fn (string $allowed): bool => str_ends_with($allowed, '\\') ? str_starts_with($import, $allowed) : $import === $allowed);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().$directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 4);
    }
}
