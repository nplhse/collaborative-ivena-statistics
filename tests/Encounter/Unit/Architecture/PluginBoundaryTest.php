<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Encounter schema plugins use only the contract of the application (ADR 037), and the application knows no
 * plugin. Deptrac reports the same rule (layer Encounter_Plugin) but does not fail CI, this test does.
 */
final class PluginBoundaryTest extends TestCase
{
    private const string PLUGINS = '/tests/Fixtures/Plugin';

    public function testPluginsImportOnlyTheEncounterContractFromTheApplication(): void
    {
        $violations = [];
        foreach ($this->phpFiles(self::PLUGINS) as $file) {
            preg_match_all('/^use (App\\\\[^;\s]+)/m', (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $import) {
                if (!str_starts_with($import, 'App\\Encounter\\Contract\\') && !str_starts_with($import, 'App\\Tests\\Fixtures\\Plugin\\')) {
                    $violations[] = substr($file, \strlen($this->root())).': '.$import;
                }
            }
        }

        self::assertNotEmpty($this->phpFiles(self::PLUGINS));
        self::assertSame([], $violations);
    }

    public function testTheApplicationNamesNoPlugin(): void
    {
        $violations = [];
        foreach ($this->phpFiles('/src') as $file) {
            // Class and namespace references; the schema name `inpatient_stay` may appear in docs as an example.
            if (1 === preg_match('/InpatientStay|Fixtures\\\\Plugin/', (string) file_get_contents($file))) {
                $violations[] = substr($file, \strlen($this->root()));
            }
        }

        self::assertSame([], $violations);
    }

    public function testThePatternFindsAForbiddenImport(): void
    {
        self::assertSame(1, preg_match_all('/^use (App\\\\[^;\s]+)/m', "use App\\Allocation\\Domain\\Entity\\Hospital;\n"));
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
