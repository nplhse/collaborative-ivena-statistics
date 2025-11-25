<?php

namespace App\Import\UI\Http\Controller;

use App\Import\Domain\Entity\Import;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ShowImportController extends AbstractController
{
    #[Route('/import/{id}', name: 'app_import_show')]
    public function __invoke(Import $import): Response
    {
        return $this->render('@Import/show.html.twig', [
            'import' => $import,
        ]);
    }
}
