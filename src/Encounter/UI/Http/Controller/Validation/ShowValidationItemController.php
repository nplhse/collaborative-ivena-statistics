<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Validation;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchValidationReaderInterface;
use App\Encounter\Application\Contract\MatchValidatorInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Enum\ValidationVerdict;
use App\Encounter\UI\Form\ValidationVerdictType;
use App\Encounter\UI\Validation\ValidationItemFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * One validation item (#1057): the comparison as in the review and the verdict. After a verdict the next
 * item without verdict opens; "wrong" on a pair also rejects the pair.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowValidationItemController extends AbstractController
{
    public function __construct(
        private readonly MatchValidationReaderInterface $validation,
        private readonly MatchValidatorInterface $validator,
        private readonly ValidationItemFactory $factory,
    ) {
    }

    #[Route('/encounter/review/{id}/validation/{sampleId}/items/{itemId}', name: 'app_encounter_validation_item', requirements: ['id' => '\d+', 'sampleId' => '\d+', 'itemId' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $sampleId, int $itemId, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        $hospitalId = (int) $hospital->getId();

        $sample = $this->validation->sample($hospitalId, $sampleId) ?? throw $this->createNotFoundException('Sample not found in this hospital.');
        $item = $this->validation->item($sample, $itemId) ?? throw $this->createNotFoundException('Item not found in this sample.');

        $form = $this->createForm(ValidationVerdictType::class, ['verdict' => $item->getVerdict()], ['verdicts' => $item->getStratum()->verdicts()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{verdict: ValidationVerdict} $data */
            $data = $form->getData();
            $rejected = $this->validator->record($item, $data['verdict'], $user);
            $this->addFlash('success', new TranslatableMessage($rejected ? 'encounter.validation.flash.recorded_rejected' : 'encounter.validation.flash.recorded', domain: 'encounter'));

            $items = $this->validation->items($sample);
            $next = array_find($items, static fn (MatchValidationItem $other): bool => !$other->getVerdict() instanceof ValidationVerdict && $other->getPosition() > $item->getPosition())
                ?? array_find($items, static fn (MatchValidationItem $other): bool => !$other->getVerdict() instanceof ValidationVerdict);
            if (null === $next) {
                $this->addFlash('info', new TranslatableMessage('encounter.validation.flash.sample_done', domain: 'encounter'));

                return $this->redirectToRoute('app_encounter_validation_sample', ['id' => $hospitalId, 'sampleId' => $sampleId]);
            }

            return $this->redirectToRoute('app_encounter_validation_item', ['id' => $hospitalId, 'sampleId' => $sampleId, 'itemId' => $next->getId()]);
        }

        return $this->render('@Encounter/validation/item.html.twig', [
            'hospital' => $hospital,
            'sample' => $sample,
            'item' => $item,
            'page' => $this->factory->create($item),
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
