<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchDeciderInterface;
use App\Encounter\Application\Review\MatchDecisionException;
use App\Encounter\Application\Security\EncounterAttribute;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Confirms, changes or rejects a pair of the allocation (#1054). "Leave open" writes nothing.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class DecideMatchController extends AbstractController
{
    public function __construct(
        private readonly MatchDeciderInterface $decider,
    ) {
    }

    #[Route('/encounter/review/{id}/allocations/{allocationId}/decide', name: 'app_encounter_review_decide', requirements: ['id' => '\d+', 'allocationId' => '\d+'], methods: ['POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $allocationId, #[CurrentUser] User $user): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        if (!$this->isCsrfTokenValid('encounter_review_'.(int) $hospital->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $hospitalId = (int) $hospital->getId();
        $encounterId = $request->request->getInt('encounter');
        $decision = $request->request->getString('decision');
        if (!\in_array($decision, ['confirm', 'reject'], true)) {
            throw $this->createNotFoundException('Unknown decision.');
        }

        try {
            if ('confirm' === $decision) {
                $replaced = $this->decider->confirm($hospitalId, $allocationId, $encounterId, $user);
                $message = null === $replaced ? 'encounter.review.flash.confirmed' : 'encounter.review.flash.changed';
            } else {
                $this->decider->reject($hospitalId, $allocationId, $encounterId, $user);
                $message = 'encounter.review.flash.rejected';
            }

            $this->addFlash('success', new TranslatableMessage($message, domain: 'encounter'));
        } catch (MatchDecisionException $exception) {
            if (MatchDecisionException::NOT_FOUND === $exception->reason) {
                throw $this->createNotFoundException('Allocation or visit not found in this hospital.', $exception);
            }

            $this->addFlash('warning', new TranslatableMessage($exception->translationKey(), domain: 'encounter'));
        }

        return $this->redirectToRoute('app_encounter_review_allocation', ['id' => $hospitalId, 'allocationId' => $allocationId, ...$this->backQuery($request)]);
    }

    /**
     * @return array<string, string>
     */
    private function backQuery(Request $request): array
    {
        return array_filter(
            ['from' => $request->request->getString('from'), 'to' => $request->request->getString('to'), 'filter' => $request->request->getString('filter')],
            static fn (string $value): bool => '' !== $value,
        );
    }
}
