<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Validation;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchValidationReaderInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Application\Validation\ScoreBands;
use App\Encounter\Application\Validation\ValidationReport;
use App\Encounter\Domain\Entity\MatchValidationItem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One validation sample (#1057): its report per stratum and score band, weighted by the population when
 * it was drawn, and its items with their verdicts.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowValidationSampleController extends AbstractController
{
    public function __construct(
        private readonly MatchValidationReaderInterface $validation,
    ) {
    }

    #[Route('/encounter/review/{id}/validation/{sampleId}', name: 'app_encounter_validation_sample', requirements: ['id' => '\d+', 'sampleId' => '\d+'], methods: ['GET'])]
    public function __invoke(Hospital $hospital, int $sampleId): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);

        $sample = $this->validation->sample((int) $hospital->getId(), $sampleId) ?? throw $this->createNotFoundException('Sample not found in this hospital.');
        $items = $this->validation->items($sample);
        $report = ValidationReport::build(
            new ScoreBands($sample->getScoreBands()),
            array_map(static fn (MatchValidationItem $item): array => ['stratum' => $item->getStratum(), 'band' => $item->getBand(), 'verdict' => $item->getVerdict()], $items),
            $sample->getPopulation(),
        );
        $next = array_find($items, static fn (MatchValidationItem $item): bool => !$item->getVerdict() instanceof \App\Encounter\Domain\Enum\ValidationVerdict);

        return $this->render('@Encounter/validation/sample.html.twig', [
            'hospital' => $hospital,
            'sample' => $sample,
            'items' => $items,
            'report' => $report,
            'next' => $next,
        ]);
    }
}
