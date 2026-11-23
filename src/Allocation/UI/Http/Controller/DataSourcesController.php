<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller;

use App\Allocation\Application\Contracts\DistrictBoundarySourcesInterface;
use App\Allocation\Application\DataSources\DataSourceCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public page with the geographic data sources, their licences and source notes (#1516).
 */
final class DataSourcesController extends AbstractController
{
    public function __construct(
        private readonly DistrictBoundarySourcesInterface $boundarySources,
        private readonly DataSourceCatalog $catalog,
        #[Autowire(param: 'app.map_tiles.url')]
        private readonly string $tileUrl,
        #[Autowire(param: 'app.map_tiles.attribution')]
        private readonly string $tileAttribution,
    ) {
    }

    #[Route('/data-sources', name: 'app_data_sources', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Allocation/data_sources/index.html.twig', [
            'sources' => $this->catalog->all($this->boundarySources->all(), $this->tileUrl, $this->tileAttribution),
        ]);
    }
}
