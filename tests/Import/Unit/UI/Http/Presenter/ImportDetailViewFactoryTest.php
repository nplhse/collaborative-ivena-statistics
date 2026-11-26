<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\UI\Http\Presenter;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\DTO\ImportTypeDetailSection;
use App\Import\Application\Service\ImportTypeExtensionRegistry;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Import\UI\Http\Presenter\Coverage\ImportCoverageViewFactory;
use App\Import\UI\Http\Presenter\ImportDetailViewFactory;
use App\Tests\Import\Doubles\Service\StubImportTypeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ImportDetailViewFactoryTest extends TestCase
{
    public function testAllocationProfileKeepsExplorerLinksAndDeduplication(): void
    {
        $import = $this->import(ImportType::ALLOCATION, 42, 7, 1245, 'Morning upload');
        $view = $this->factory()->create($import);

        self::assertSame('label.import.type.allocation', $view->typeLabel);
        self::assertSame('import.summary.allocations_imported:1245', $view->importedRecordsLabel);
        self::assertTrue($view->showsDeduplication);
        self::assertTrue($view->showsDeduplicationBreakdown);
        self::assertSame('import.delete.modal.body:Morning upload', $view->deleteConfirmation);
        self::assertCount(2, $view->recordActions);
        self::assertSame('app_explore_allocation_list?importId=42', $view->recordActions[0]->url);
        self::assertSame('import.action.preview_rows', $view->recordActions[0]->label);
        self::assertSame('app_explore_mci_case_list?importId=42', $view->recordActions[1]->url);
        self::assertSame('import.action.preview_mci_cases', $view->recordActions[1]->label);
    }

    public function testClosureProfileLinksAnalyticsForTheHospital(): void
    {
        $import = $this->import(ImportType::CLOSURE, 9, 18, 382, 'Night closures');
        $view = $this->factory()->create($import);

        self::assertSame('label.import.type.closure', $view->typeLabel);
        self::assertSame('import.summary.closures_imported:382', $view->importedRecordsLabel);
        self::assertFalse($view->showsDeduplication);
        self::assertFalse($view->showsDeduplicationBreakdown);
        self::assertSame('import.delete.modal.body.closure:Night closures', $view->deleteConfirmation);
        self::assertCount(1, $view->recordActions);
        self::assertSame('import.action.open_closure_analytics', $view->recordActions[0]->label);
        self::assertSame('tabler:calendar-off', $view->recordActions[0]->icon);
        self::assertSame('app_stats_closure_analytics?closureHospitals%5B0%5D=18', $view->recordActions[0]->url);
        self::assertStringNotContainsString('explore', $view->recordActions[0]->url);
    }

    public function testEncounterProfileShowsDuplicatesWithoutTheAllocationBreakdown(): void
    {
        $view = $this->factory()->create($this->import(ImportType::ENCOUNTER, 5, 18, 40, 'Visits'));

        self::assertTrue($view->showsDeduplication, 'known visits are counted as duplicates');
        self::assertFalse($view->showsDeduplicationBreakdown, 'known visits are skipped, never discarded or replaced (#1418)');
    }

    public function testEncounterProfileCountsTheInsertedVisitsAsImported(): void
    {
        // 40 rows passed validation, but 28 of them were known visits; only 12 were inserted (#1463).
        $section = new ImportTypeDetailSection('@Encounter/imports/_import_section.html.twig', importedRecordCount: 12);
        $factory = $this->factory(new ImportTypeExtensionRegistry([new StubImportTypeExtension(true, [], $section)]));

        $view = $factory->create($this->import(ImportType::ENCOUNTER, 5, 18, 40, 'Visits'));

        self::assertSame('import.summary.encounters_imported:12', $view->importedRecordsLabel);
    }

    public function testEncounterProfileFallsBackToThePassedRowsWithoutAnEncounterSection(): void
    {
        $view = $this->factory()->create($this->import(ImportType::ENCOUNTER, 5, 18, 40, 'Visits'));

        self::assertSame('import.summary.encounters_imported:40', $view->importedRecordsLabel);
    }

    public function testCreateRejectsAnUnpersistedImport(): void
    {
        $import = new Import()->setType(ImportType::ALLOCATION);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Import must be persisted.');

        $this->factory()->create($import);
    }

    public function testCreateRejectsAnImportWithoutType(): void
    {
        $import = new Import();
        $importId = new \ReflectionProperty(Import::class, 'id');
        $importId->setValue($import, 3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Import type must be set.');

        $this->factory()->create($import);
    }

    public function testClosureProfileOmitsHospitalFilterWhenHospitalHasNoId(): void
    {
        $import = $this->import(ImportType::CLOSURE, 9, null, 1, null);
        $view = $this->factory()->create($import);

        self::assertSame('app_stats_closure_analytics', $view->recordActions[0]->url);
        self::assertSame('import.delete.modal.body.closure:#9', $view->deleteConfirmation);
    }

    private function factory(?ImportTypeExtensionRegistry $typeExtensions = null): ImportDetailViewFactory
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []): string {
                if ([] === $parameters) {
                    return $id;
                }

                $value = $parameters['count'] ?? $parameters['name'] ?? '';

                return $id.':'.$value;
            },
        );

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static function (string $route, array $parameters = []): string {
                if ([] === $parameters) {
                    return $route;
                }

                return $route.'?'.http_build_query($parameters);
            },
        );

        return new ImportDetailViewFactory($translator, $urlGenerator, new ImportCoverageViewFactory(), $typeExtensions ?? new ImportTypeExtensionRegistry());
    }

    private function import(
        ImportType $type,
        int $importId,
        ?int $hospitalId,
        int $rowsPassed,
        ?string $name,
    ): Import {
        $hospital = new Hospital();
        if (null !== $hospitalId) {
            $hospitalIdProperty = new \ReflectionProperty(Hospital::class, 'id');
            $hospitalIdProperty->setValue($hospital, $hospitalId);
        }

        $import = new Import()
            ->setType($type)
            ->setHospital($hospital)
            ->setRowsPassed($rowsPassed);

        if (null !== $name) {
            $import->setName($name);
        }

        $importIdProperty = new \ReflectionProperty(Import::class, 'id');
        $importIdProperty->setValue($import, $importId);

        return $import;
    }
}
