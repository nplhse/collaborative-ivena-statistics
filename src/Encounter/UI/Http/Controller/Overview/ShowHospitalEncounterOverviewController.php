<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Overview;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogEntry;
use App\Encounter\Application\Overview\EncounterOverviewFactory;
use App\Encounter\Application\Overview\EncounterPeriod;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\UI\Glossary\MtsNames;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Descriptive overview of the encounter data of one hospital (#1055): beta role and VIEW_AGGREGATES
 * on that hospital (any encounter grant plus the hospital permission STATISTICS; administrators all
 * hospitals). Never aggregates across hospitals.
 */
final class ShowHospitalEncounterOverviewController extends AbstractController
{
    public function __construct(
        private readonly EncounterOverviewFactory $overviewFactory,
        private readonly MtsCatalog $mtsCatalog,
        private readonly MtsNames $mtsNames,
    ) {
    }

    #[Route('/encounter/hospital/{id}', name: 'app_encounter_hospital_overview', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(EncounterAttribute::VIEW_AGGREGATES, subject: 'hospital')]
    public function __invoke(Hospital $hospital, Request $request): Response
    {
        $period = EncounterPeriod::fromQuery($request->query->getString('from') ?: null, $request->query->getString('to') ?: null);
        $overview = $this->overviewFactory->create((int) $hospital->getId(), $period);

        $diagramNames = [];
        foreach ($overview->diagrams as $row) {
            $entry = $this->mtsCatalog->diagram($row->key);
            $diagramNames[$row->key] = $entry instanceof MtsCatalogEntry ? $this->mtsNames->diagram($entry)->name : $row->key;
        }

        return $this->render('@Encounter/overview/hospital.html.twig', [
            'hospital' => $hospital,
            'overview' => $overview,
            'diagram_names' => $diagramNames,
        ]);
    }
}
