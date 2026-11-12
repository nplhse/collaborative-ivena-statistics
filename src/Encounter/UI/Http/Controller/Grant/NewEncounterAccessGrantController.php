<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Grant;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\HospitalMembershipReaderInterface;
use App\Encounter\Application\Grant\EncounterGrantException;
use App\Encounter\Application\Grant\EncounterGrantInput;
use App\Encounter\Application\Grant\EncounterGrantManager;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\UI\Form\EncounterAccessGrantType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class NewEncounterAccessGrantController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly HospitalMembershipReaderInterface $membershipReader,
        private readonly EncounterGrantManager $grantManager,
    ) {
    }

    #[Route('/hospitals/{id}/edit/encounter-access/new', name: 'app_encounter_grants_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::MANAGE_GRANTS, $hospital);

        $input = new EncounterGrantInput();
        $form = $this->createForm(EncounterAccessGrantType::class, $input, [
            'is_create' => true,
            'user_choices' => $this->eligibleUsers($hospital),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        }

        if ($form->isSubmitted() && $form->isValid() && $input->user instanceof User) {
            $actor = $this->getUser();
            try {
                $this->grantManager->grant($hospital, $input->user, $input->actions, $actor instanceof User ? $actor : null);
            } catch (EncounterGrantException $exception) {
                $this->addFlash('error', new TranslatableMessage($exception->translationKey(), domain: 'encounter'));

                return $this->redirectToRoute('app_encounter_grants_new', ['id' => $hospital->getId()]);
            }

            $this->addFlash('success', new TranslatableMessage('encounter.grant.flash.created', domain: 'encounter'));

            return $this->redirectToRoute('app_encounter_grants', ['id' => $hospital->getId()]);
        }

        return $this->render('@Encounter/grants/new.html.twig', [
            'hospital' => $hospital,
            'form' => $form,
        ]);
    }

    /**
     * Owner and users with a hospital access grant who hold no encounter grant yet.
     *
     * @return list<User>
     */
    private function eligibleUsers(Hospital $hospital): array
    {
        $grantedUserIds = array_map(
            static fn (EncounterAccessGrant $grant): ?int => $grant->getUser()->getId(),
            $this->grantRepository->findForHospital($hospital),
        );

        return array_values(array_filter(
            $this->membershipReader->findMembers($hospital),
            static fn (User $user): bool => !\in_array($user->getId(), $grantedUserIds, true),
        ));
    }
}
