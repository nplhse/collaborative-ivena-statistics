<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\DataCatalog;

use App\Allocation\Application\Explore\DataCatalog\DataCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListDataCatalogController extends AbstractController
{
    public function __construct(private readonly DataCatalog $dataCatalog)
    {
    }

    #[Route('/explore/data-catalog', name: 'app_explore_data_catalog_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Allocation/data_catalog/index.html.twig', [
            'objects' => $this->dataCatalog->visibleObjects(),
        ]);
    }
}
