<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Import;

use App\Encounter\Application\Contract\EncounterImportReaderInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Enum\ImportStatus;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Deletes an encounter import (ADR 027, ADR 028): its visits with their IVENA links and review
 * decisions, its rejects, report and source file. Visits of other imports stay. Needs UPLOAD
 * (DELETE_IMPORT) on the hospital; audited as `encounter.import.deleted`.
 */
#[IsGranted(UserRole::ENCOUNTER_BETA)]
final class DeleteEncounterImportController extends AbstractController
{
    public function __construct(
        private readonly EncounterImportReaderInterface $encounterImports,
        private readonly ImportDeletionService $deletionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/encounter/imports/{id}/delete', name: 'app_encounter_import_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(int $id, Request $request): RedirectResponse
    {
        $encounterImport = $this->encounterImports->findForImportId($id);
        $hospital = $encounterImport?->getImport()->getHospital();
        if (!$encounterImport instanceof \App\Encounter\Domain\Entity\EncounterImport || !$hospital instanceof \App\Allocation\Domain\Entity\Hospital || !$this->isGranted(EncounterAttribute::UPLOAD, $hospital)) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EncounterAttribute::DELETE_IMPORT, $hospital);
        if (!$this->isCsrfTokenValid('encounter_import_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $import = $encounterImport->getImport();
        if (ImportStatus::RUNNING === $import->getStatus()) {
            $this->addFlash('error', new TranslatableMessage('flash.import.delete_running_blocked', domain: 'import'));

            return $this->redirectToRoute('app_encounter_import_show', ['id' => $id]);
        }

        $this->auditContext->beginIntent('encounter.import.deleted', [
            'import_id' => $id,
            'hospital_id' => $hospital->getId(),
            'visits' => $encounterImport->getInserted(),
        ]);
        try {
            // Removed in the same flush as the import, so the audit log records it too.
            $this->entityManager->remove($encounterImport);
            $this->deletionService->delete($import);
        } finally {
            $this->auditContext->endIntent();
        }

        $this->addFlash('success', new TranslatableMessage('encounter.import.flash.deleted', domain: 'encounter'));

        return $this->redirectToRoute('app_encounter_imports');
    }
}
