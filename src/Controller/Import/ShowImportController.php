<?php

namespace App\Controller\Import;

use App\Entity\Import;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ShowImportController extends AbstractController
{
    #[Route('/data/import/{id}', name: 'app_import_show')]
    public function __invoke(Import $import): Response
    {
        return $this->render('import/show.html.twig', [
            'import' => $import,
        ]);
    }
}
