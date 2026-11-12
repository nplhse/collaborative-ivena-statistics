<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Import;

use App\Encounter\Application\Contract\EncounterImportReaderInterface;
use App\Encounter\Application\Import\ImportEncountersDispatcher;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Processes the retained file of an encounter import again (ADR 028): the run first removes the
 * import's visits with their links and review decisions, then imports the file with the same
 * duplicate check. Needs UPLOAD (REPROCESS_SOURCE_FILE) on the hospital; shows no file content.
 */
#[IsGranted(UserRole::ENCOUNTER_BETA)]
final class ReprocessEncounterImportController extends AbstractController
{
    public function __construct(
        private readonly EncounterImportReaderInterface $encounterImports,
        private readonly ImportEncountersDispatcher $dispatcher,
        private readonly ImportFileStorage $fileStorage,
        #[Target('import')]
        private readonly LoggerInterface $importLogger,
    ) {
    }

    #[Route('/encounter/imports/{id}/reprocess', name: 'app_encounter_import_reprocess', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(int $id, Request $request): RedirectResponse
    {
        $encounterImport = $this->encounterImports->findForImportId($id);
        $hospital = $encounterImport?->getImport()->getHospital();
        if (!$encounterImport instanceof \App\Encounter\Domain\Entity\EncounterImport || !$hospital instanceof \App\Allocation\Domain\Entity\Hospital || !$this->isGranted(EncounterAttribute::UPLOAD, $hospital)) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EncounterAttribute::REPROCESS_SOURCE_FILE, $hospital);
        if (!$this->isCsrfTokenValid('encounter_import_reprocess_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $import = $encounterImport->getImport();
        if (ImportStatus::RUNNING === $import->getStatus()) {
            $this->addFlash('error', new TranslatableMessage('flash.import.reprocess_running_blocked', domain: 'import'));

            return $this->redirectToRoute('app_encounter_import_show', ['id' => $id]);
        }

        if ($encounterImport->isSourceFileDeleted() || !$this->fileStorage->exists($import->getFilePath())) {
            $this->addFlash('error', new TranslatableMessage('encounter.import.flash.source_file_missing', domain: 'encounter'));

            return $this->redirectToRoute('app_encounter_import_show', ['id' => $id]);
        }

        $user = $this->getUser();
        try {
            $this->dispatcher->dispatch($id, ImportRunTrigger::Reprocess, $user instanceof User ? $user->getId() : null);
        } catch (DispatchException $e) {
            $this->importLogger->error('encounter.import.reprocess.dispatch_failed', ['id' => $id, 'msg' => $e->getMessage()]);
            $this->addFlash('error', new TranslatableMessage('flash.import.reprocess_failed', domain: 'import'));

            return $this->redirectToRoute('app_encounter_import_show', ['id' => $id]);
        }

        $this->addFlash('success', new TranslatableMessage('flash.import.reprocess_queued', domain: 'import'));

        return $this->redirectToRoute('app_encounter_import_show', ['id' => $id]);
    }
}
