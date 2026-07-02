<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Import\Application\Exception\ImportSourceFileNotFoundException;
use App\Import\Application\Service\ImportSourceFileDownloadService;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\Shared\Infrastructure\Audit\AuditContext;
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
        private readonly AuditContext $auditContext,
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
            $this->auditContext->beginIntent('import.source_file.downloaded', [
                'import_id' => $importId,
            ]);

            return $this->downloadService->createDownloadResponse($import);
        } catch (ImportSourceFileNotFoundException) {
            throw new NotFoundHttpException();
        }
    }
}
