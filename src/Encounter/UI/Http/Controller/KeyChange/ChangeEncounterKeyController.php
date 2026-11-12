<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\KeyChange;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterKeyChangerInterface;
use App\Encounter\Application\Contract\EncounterKeyChangeSummaryReaderInterface;
use App\Encounter\Application\KeyChange\EncounterKeyChangeInProgressException;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Enum\EncounterKeyChangeOption;
use App\Encounter\UI\Form\EncounterKeyChangeType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Danger action: change the pseudonym key of a hospital (ADR 026, #1050). Hospital owner or
 * administrator (EncounterAttribute::CHANGE_KEY); users with the upload grant cannot.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ChangeEncounterKeyController extends AbstractController
{
    public function __construct(
        private readonly EncounterKeyChangeSummaryReaderInterface $summaryQuery,
        private readonly EncounterKeyChangerInterface $keyChanger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/hospitals/{id}/edit/encounter-key', name: 'app_encounter_key_change', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::CHANGE_KEY, $hospital);

        $hospitalName = (string) $hospital->getName();
        $summary = $this->summaryQuery->forHospital((int) $hospital->getId());
        $form = $this->createForm(EncounterKeyChangeType::class, null, ['hospital_name' => $hospitalName]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        }

        if ($form->isSubmitted() && $form->isValid() && !$summary['key_change_in_progress']) {
            /** @var array{option: EncounterKeyChangeOption, confirmName: string} $data */
            $data = $form->getData();
            if (trim($data['confirmName']) !== trim($hospitalName)) {
                $form->get('confirmName')->addError(new FormError($this->translator->trans('encounter.key_change.field.confirm_name_mismatch', [], 'encounter')));
            } else {
                $actor = $this->getUser();
                try {
                    $this->keyChanger->change($hospital, $data['option'], $actor instanceof User ? $actor : null);
                } catch (EncounterKeyChangeInProgressException) {
                    $this->addFlash('error', new TranslatableMessage('encounter.key_change.flash.in_progress', domain: 'encounter'));

                    return $this->redirectToRoute('app_encounter_key_change', ['id' => $hospital->getId()]);
                }

                $this->addFlash('success', new TranslatableMessage(
                    'encounter.key_change.flash.changed',
                    ['option' => $data['option']->value],
                    'encounter',
                ));

                return $this->redirectToRoute('app_encounter_key_change', ['id' => $hospital->getId()]);
            }
        }

        return $this->render('@Encounter/key_change/index.html.twig', [
            'hospital' => $hospital,
            'summary' => $summary,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
