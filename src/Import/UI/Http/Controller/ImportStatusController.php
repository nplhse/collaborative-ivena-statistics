<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\Import\UI\Http\Presenter\ImportStatusPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class ImportStatusController extends AbstractController
{
    public function __construct(
        private readonly ImportStatusPresenter $statusPresenter,
    ) {
    }

    #[Route('/import/{id}/status', name: 'app_import_status')]
    #[IsGranted(ImportVoter::VIEW, subject: 'import')]
    public function __invoke(Import $import): \Symfony\Component\HttpFoundation\JsonResponse
    {
        return $this->json($this->statusPresenter->present($import), Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
