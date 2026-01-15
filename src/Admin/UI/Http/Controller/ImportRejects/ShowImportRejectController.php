<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\ImportRejects;

use App\Import\Infrastructure\Repository\ImportRejectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/import-rejects/{id}', name: 'app_admin_import_reject_show', requirements: ['id' => '\d+'])]
final class ShowImportRejectController extends AbstractController
{
    public function __construct(
        private readonly ImportRejectRepository $importRejectRepository,
    ) {
    }

    public function __invoke(int $id): Response
    {
        $reject = $this->importRejectRepository->find($id);

        if (null === $reject) {
            throw $this->createNotFoundException('ImportReject not found.');
        }

        $rowJson = json_encode($reject->getRow(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->render('@Admin/import_rejects/show.html.twig', [
            'reject' => $reject,
            'row_json' => $rowJson ?: '{}',
        ]);
    }
}
