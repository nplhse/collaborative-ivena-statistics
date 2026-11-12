<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Import;

use App\Encounter\Application\Contract\EncounterImportReaderInterface;
use App\Encounter\Application\Contract\EncounterImportReportInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Import\Application\Service\ImportFileStorage;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Status, counts, file rejection, row rejects (line, field, code) and suspected duplicates of one
 * encounter import (#1048). Needs UPLOAD on the import's hospital; never shows file content.
 */
#[IsGranted(UserRole::ENCOUNTER_BETA)]
final class ShowEncounterImportController extends AbstractController
{
    private const int LIST_LIMIT = 200;

    public function __construct(
        private readonly EncounterImportReaderInterface $encounterImports,
        private readonly EncounterImportReportInterface $report,
        private readonly ImportFileStorage $fileStorage,
    ) {
    }

    #[Route('/encounter/imports/{id}', name: 'app_encounter_import_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function __invoke(int $id): Response
    {
        $encounterImport = $this->encounterImports->findForImportId($id);
        $hospital = $encounterImport?->getImport()->getHospital();
        if (!$encounterImport instanceof \App\Encounter\Domain\Entity\EncounterImport || !$hospital instanceof \App\Allocation\Domain\Entity\Hospital || !$this->isGranted(EncounterAttribute::UPLOAD, $hospital)) {
            // A visit or import of another hospital is not found (ADR 027).
            throw $this->createNotFoundException();
        }

        $import = $encounterImport->getImport();

        return $this->render('@Encounter/imports/show.html.twig', [
            'encounter_import' => $encounterImport,
            'import' => $import,
            'rejects' => $this->report->rejects($id, self::LIST_LIMIT),
            'suspected_duplicates' => $this->report->suspectedDuplicates($id, self::LIST_LIMIT),
            'list_limit' => self::LIST_LIMIT,
            'can_reprocess' => $this->isGranted(EncounterAttribute::REPROCESS_SOURCE_FILE, $hospital)
                && !$encounterImport->isSourceFileDeleted()
                && $this->fileStorage->exists($import->getFilePath()),
        ]);
    }
}
