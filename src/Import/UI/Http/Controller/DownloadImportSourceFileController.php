<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Import\Application\Exception\ImportSourceFileNotFoundException;
use App\Import\Application\Service\ImportSourceDownloadAuditLogger;
use App\Import\Application\Service\ImportSourceFileDownloadService;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Downloads the stored source file of an import. ImportVoter::DOWNLOAD_SOURCE decides: administrators and the
 * hospital owner for allocation and closure imports (#1569); encounter imports with the encounter grant UPLOAD while
 * the file is kept (ADR 028, #1049). Every download is written to the audit log.
 */
final class DownloadImportSourceFileController extends AbstractController
{
    public function __construct(
        private readonly ImportSourceFileDownloadService $downloadService,
        private readonly ImportSourceDownloadAuditLogger $auditLogger,
    ) {
    }

    #[Route('/import/{id}/source-file', name: 'app_import_source_file_download', methods: ['GET'])]
    #[IsGranted(ImportVoter::DOWNLOAD_SOURCE, subject: 'import')]
    public function __invoke(Import $import): BinaryFileResponse|RedirectResponse
    {
        $importId = $import->getId();
        if (null === $importId) {
            throw new NotFoundHttpException();
        }

        try {
            $response = $this->downloadService->createDownloadResponse($import);
        } catch (ImportSourceFileNotFoundException) {
            // A removed file is an expected state, not an error: back to the import with a message.
            $this->addFlash('warning', new TranslatableMessage('import.source_file.missing', domain: 'import'));

            return $this->redirectToRoute('app_import_show', ['id' => $importId]);
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->auditLogger->log($actor, $import);

        return $response;
    }
}
