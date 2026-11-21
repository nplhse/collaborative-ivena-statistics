<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter;

use App\Import\Application\DTO\ImportTypeDetailSection;
use App\Import\Application\DTO\ImportTypeRecordAction;
use App\Import\Application\Service\ImportTypeExtensionRegistry;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Import\UI\Http\Presenter\Coverage\ImportCoverageViewFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ImportDetailViewFactory
{
    /**
     * Route name only. The import UI must not depend on the Statistics layer.
     * The query key matches ClosureAnalyticsFilterRequestResolver::HOSPITALS.
     */
    private const string CLOSURE_ANALYTICS_ROUTE = 'app_stats_closure_analytics';

    private const string CLOSURE_HOSPITALS_QUERY = 'closureHospitals';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private ImportCoverageViewFactory $coverageViewFactory,
        private ImportTypeExtensionRegistry $typeExtensions,
    ) {
    }

    public function create(Import $import): ImportDetailView
    {
        $id = $import->getId();
        if (null === $id) {
            throw new \InvalidArgumentException('Import must be persisted.');
        }

        $type = $import->getType();
        if (!$type instanceof ImportType) {
            throw new \InvalidArgumentException('Import type must be set.');
        }

        $importedCount = $import->getRowsPassed() ?? 0;
        $coverage = $this->coverageViewFactory->create($import);
        $typeSection = ImportType::ENCOUNTER === $type ? $this->typeExtensions->for($type)?->detailSection($import) : null;

        return match ($type) {
            ImportType::ALLOCATION => new ImportDetailView(
                typeLabel: $this->trans('label.import.type.allocation'),
                importedRecordsLabel: $this->trans('import.summary.allocations_imported', ['count' => $importedCount]),
                showsDeduplication: true,
                deleteConfirmation: $this->confirmation($import, 'import.delete.modal.body'),
                reprocessConfirmation: $this->confirmation($import, 'import.reprocess.modal.body'),
                recordActions: [
                    new ImportDetailAction(
                        label: $this->trans('import.action.preview_rows'),
                        url: $this->urlGenerator->generate('app_explore_allocation_list', ['importId' => $id]),
                        icon: 'tabler:table',
                        variant: 'outline-primary',
                    ),
                    new ImportDetailAction(
                        label: $this->trans('import.action.preview_mci_cases'),
                        url: $this->urlGenerator->generate('app_explore_mci_case_list', ['importId' => $id]),
                        icon: 'tabler:ambulance',
                        variant: 'outline-primary',
                    ),
                ],
                coverage: $coverage,
                showsDeduplicationBreakdown: true,
            ),
            ImportType::CLOSURE => new ImportDetailView(
                typeLabel: $this->trans('label.import.type.closure'),
                importedRecordsLabel: $this->trans('import.summary.closures_imported', ['count' => $importedCount]),
                showsDeduplication: false,
                deleteConfirmation: $this->confirmation($import, 'import.delete.modal.body.closure'),
                reprocessConfirmation: $this->confirmation($import, 'import.reprocess.modal.body.closure'),
                recordActions: [
                    new ImportDetailAction(
                        label: $this->trans('import.action.open_closure_analytics'),
                        url: $this->closureAnalyticsUrl($import),
                        icon: 'tabler:calendar-off',
                        variant: 'outline-primary',
                    ),
                ],
                coverage: $coverage,
            ),
            // Owned by the Encounter context, which adds its report section and record links (`import.type_extension`).
            ImportType::ENCOUNTER => new ImportDetailView(
                typeLabel: $this->trans('label.import.type.encounter'),
                importedRecordsLabel: $this->trans('import.summary.encounters_imported', ['count' => $importedCount]),
                showsDeduplication: true,
                deleteConfirmation: $this->confirmation($import, 'import.delete.modal.body.encounter'),
                reprocessConfirmation: $this->confirmation($import, 'import.reprocess.modal.body.encounter'),
                recordActions: $this->extensionRecordActions($typeSection),
                coverage: $coverage,
                typeSection: $typeSection,
            ),
        };
    }

    /**
     * @return list<ImportDetailAction>
     */
    private function extensionRecordActions(?ImportTypeDetailSection $section): array
    {
        return array_map(
            static fn (ImportTypeRecordAction $action): ImportDetailAction => new ImportDetailAction(
                label: $action->label,
                url: $action->url,
                icon: $action->icon,
                variant: 'outline-primary',
            ),
            $section->recordActions ?? [],
        );
    }

    private function closureAnalyticsUrl(Import $import): string
    {
        $hospitalId = $import->getHospital()?->getId();
        $parameters = [];
        if (null !== $hospitalId) {
            $parameters[self::CLOSURE_HOSPITALS_QUERY] = [$hospitalId];
        }

        return $this->urlGenerator->generate(self::CLOSURE_ANALYTICS_ROUTE, $parameters);
    }

    private function confirmation(Import $import, string $key): string
    {
        $name = $import->getName();
        if (null === $name || '' === $name) {
            $name = '#'.(string) $import->getId();
        }

        return $this->trans($key, ['name' => $name]);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'import');
    }
}
