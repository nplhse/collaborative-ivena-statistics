<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class DeleteImportController extends AbstractController
{
    public function __construct(
        private readonly ImportDeletionService $importDeletionService,
    ) {
    }

    #[Route('/import/{id}/delete', name: 'app_import_delete', methods: ['POST'])]
    #[IsGranted(ImportVoter::DELETE, subject: 'import')]
    public function __invoke(Import $import, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $importId = (int) $import->getId();

        if (!$this->isCsrfTokenValid('import_delete_'.$importId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (ImportStatus::RUNNING === $import->getStatus()) {
            $this->addFlash('error', 'flash.import.delete_running_blocked');

            return $this->redirectToRoute('app_import_show', ['id' => $importId]);
        }

        $this->importDeletionService->delete($import);

        $this->addFlash('success', 'flash.import.deleted');

        return $this->redirectToRoute('app_import_index');
    }
}
