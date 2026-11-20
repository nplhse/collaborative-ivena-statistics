<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\CatalogObject;
use App\Allocation\Application\Explore\DataCatalog\DataCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowDataCatalogObjectController extends AbstractController
{
    public function __construct(private readonly DataCatalog $dataCatalog)
    {
    }

    #[Route('/explore/data-catalog/{objectKey}', name: 'app_explore_data_catalog_show', requirements: ['objectKey' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    public function __invoke(string $objectKey): Response
    {
        $object = $this->dataCatalog->visibleObject($objectKey);
        if (!$object instanceof CatalogObject) {
            throw $this->createNotFoundException();
        }

        return $this->render('@Allocation/data_catalog/show.html.twig', [
            'object' => $object,
        ]);
    }
}
