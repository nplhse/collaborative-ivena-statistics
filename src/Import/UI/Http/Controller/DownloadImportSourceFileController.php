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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class DownloadImportSourceFileController extends AbstractController
{
    public function __construct(
        private readonly ImportSourceFileDownloadService $downloadService,
        private readonly ImportSourceDownloadAuditLogger $auditLogger,
    ) {
    }

    #[Route('/import/{id}/source-file', name: 'app_import_source_file_download', methods: ['GET'])]
    #[IsGranted(ImportVoter::DOWNLOAD_SOURCE, subject: 'import')]
    public function __invoke(Import $import): BinaryFileResponse
    {
        $importId = $import->getId();
        if (null === $importId) {
            throw new NotFoundHttpException();
        }

        try {
            $response = $this->downloadService->createDownloadResponse($import);
        } catch (ImportSourceFileNotFoundException) {
            throw new NotFoundHttpException();
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->auditLogger->log($actor, $import);

        return $response;
    }
}
