<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Validation;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchValidationReaderInterface;
use App\Encounter\Application\Contract\MatchValidatorInterface;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Application\Validation\ScoreBands;
use App\Encounter\Application\Validation\ValidationReport;
use App\Encounter\UI\Form\ValidationSampleType;
use App\Encounter\UI\Review\ReviewContextResolver;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Match validation of one hospital (#1057): its samples, a form to draw a new one, and the verdicts of all
 * samples per score band, separated by matcher version.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListValidationSamplesController extends AbstractController
{
    public function __construct(
        private readonly MatchValidationReaderInterface $validation,
        private readonly MatchValidatorInterface $validator,
        private readonly ReviewContextResolver $contexts,
        private readonly ScoreBands $bands,
        #[Autowire(param: 'app.encounter.validation.sample_size')]
        private readonly int $defaultSampleSize,
    ) {
    }

    #[Route('/tools/encounter-review/{id}/validation', name: 'app_tools_encounter_validation', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        $hospitalId = (int) $hospital->getId();

        $period = $this->contexts->period($request, $hospitalId);
        $form = $this->createForm(ValidationSampleType::class, [
            'from' => $period->from,
            'to' => $period->to,
            'sample_size' => $this->defaultSampleSize,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{from: \DateTimeImmutable, to: \DateTimeImmutable, sample_size: int} $data */
            $data = $form->getData();
            $sample = $this->validator->draw($hospitalId, new ReviewPeriod($data['from'], $data['to']), $data['sample_size'], $user);
            $this->addFlash('success', new TranslatableMessage('encounter.validation.flash.drawn', domain: 'encounter'));

            return $this->redirectToRoute('app_tools_encounter_validation_sample', ['id' => $hospitalId, 'sampleId' => $sample->getId()]);
        }

        $reports = [];
        foreach ($this->validation->itemsByMatcherVersion($hospitalId) as $version => $items) {
            $reports[$version] = ValidationReport::build($this->bands, $items, null);
        }

        return $this->render('@Encounter/validation/index.html.twig', [
            'hospital' => $hospital,
            'samples' => $this->validation->samples($hospitalId),
            'reports' => $reports,
            'form' => $form,
            'max_days' => ReviewPeriod::MAX_DAYS,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
