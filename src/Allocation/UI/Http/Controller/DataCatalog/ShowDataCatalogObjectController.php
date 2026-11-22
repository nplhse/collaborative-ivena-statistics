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

        $timezone = date_default_timezone_get();

        return $this->render('@Allocation/data_catalog/show.html.twig', [
            'object' => $object,
            'timezone' => $timezone,
            'timeZoneExamples' => $object->hasLocalTime() ? $this->timeZoneExamples() : [],
        ]);
    }

    /**
     * A winter and a summer time of day in the application time zone and the same instant in UTC, for the
     * note on reconstructing absolute times (#1499).
     *
     * @return list<array{local: string, utc: string}>
     */
    private function timeZoneExamples(): array
    {
        $utc = new \DateTimeZone('UTC');

        return array_map(
            static fn (string $local): array => [
                'local' => $local,
                'utc' => new \DateTimeImmutable($local)->setTimezone($utc)->format('Y-m-d H:i'),
            ],
            ['2026-01-15 14:30', '2026-07-01 14:30'],
        );
    }
}
