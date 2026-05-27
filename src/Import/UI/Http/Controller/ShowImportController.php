<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class ShowImportController extends AbstractController
{
    #[Route('/import/{id}', name: 'app_import_show')]
    #[IsGranted(ImportVoter::VIEW, subject: 'import')]
    public function __invoke(Import $import): Response
    {
        return $this->render('@Import/show.html.twig', [
            'import' => $import,
        ]);
    }
}
