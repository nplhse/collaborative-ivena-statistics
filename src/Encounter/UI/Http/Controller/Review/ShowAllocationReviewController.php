<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchDeciderInterface;
use App\Encounter\Application\Review\MatchDecisionException;
use App\Encounter\Application\Review\MatchReviewNavigator;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\UI\Form\MatchReviewDecisionType;
use App\Encounter\UI\Review\AllocationReviewFactory;
use App\Encounter\UI\Review\ReviewContextResolver;
use App\Encounter\UI\Review\ReviewNextUrl;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Review page of one allocation (#1054), laid out like the indication review: context on the left, the
 * decision on the right (candidate visits with the contribution of each feature), actions at the bottom.
 * Confirm (or change) and reject continue with the next allocation of the segment; "leave open" is the
 * skip link (NavigateMatchReviewController).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowAllocationReviewController extends AbstractController
{
    public function __construct(
        private readonly AllocationReviewFactory $factory,
        private readonly ReviewContextResolver $contexts,
        private readonly MatchReviewNavigator $navigator,
        private readonly ReviewNextUrl $nextUrl,
        private readonly MatchDeciderInterface $decider,
    ) {
    }

    #[Route('/tools/encounter-review/{id}/allocations/{allocationId}', name: 'app_tools_encounter_review_allocation', requirements: ['id' => '\d+', 'allocationId' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $allocationId, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        $hospitalId = (int) $hospital->getId();

        $page = $this->factory->create($hospitalId, $allocationId);
        if (null === $page) {
            throw $this->createNotFoundException('Allocation not found in this hospital.');
        }

        $context = $this->contexts->resolve($request, $hospitalId);
        $position = $this->navigator->position($hospitalId, $context, $allocationId)
            ?? ($request->query->has('pos') ? $request->query->getInt('pos') : null);
        $here = $this->nextUrl->allocation($hospitalId, $context, $allocationId);

        $form = $this->createForm(MatchReviewDecisionType::class, ['encounter' => $page['selected']], [
            'candidates' => array_column($page['candidates'], 'encounter_id'),
            'action' => $here,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{encounter: int} $data */
            $data = $form->getData();
            try {
                if ($this->clicked($form, 'reject')) {
                    $this->decider->reject($hospitalId, $allocationId, $data['encounter'], $user);
                    $message = 'encounter.review.flash.rejected';
                } else {
                    $replaced = $this->decider->confirm($hospitalId, $allocationId, $data['encounter'], $user);
                    $message = null === $replaced ? 'encounter.review.flash.confirmed' : 'encounter.review.flash.changed';
                }
            } catch (MatchDecisionException $exception) {
                if (MatchDecisionException::NOT_FOUND === $exception->reason) {
                    throw $this->createNotFoundException('Allocation or visit not found in this hospital.', $exception);
                }

                $this->addFlash('warning', new TranslatableMessage($exception->translationKey(), domain: 'encounter'));

                return $this->redirect($here);
            }

            $this->addFlash('success', new TranslatableMessage($message, domain: 'encounter'));
            $next = $this->nextUrl->after($hospitalId, $context, $allocationId, $position);
            if ($next['done']) {
                $this->addFlash('info', new TranslatableMessage('encounter.review.flash.segment_done', domain: 'encounter'));
            }

            return $this->redirect($next['url']);
        }

        return $this->render('@Encounter/review/allocation.html.twig', [
            ...$page,
            'hospital' => $hospital,
            'context' => $context,
            'position' => $position,
            'form' => $form,
            'header_status' => $this->headerStatus($page),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function clicked(FormInterface $form, string $button): bool
    {
        $child = $form->get($button);

        return $child instanceof ClickableInterface && $child->isClicked();
    }

    /**
     * Status of the allocation for the page header: its link, else open conflict, proposal or nothing.
     *
     * @param array<string, mixed> $page
     */
    private function headerStatus(array $page): string
    {
        $link = $page['link'];
        if (\is_array($link) && $link['status'] instanceof MatchStatus) {
            return $link['status']->value;
        }

        /** @var list<array{stored: ?array{status: MatchStatus, conflict: bool}, rival_allocations: list<int>}> $candidates */
        $candidates = $page['candidates'];
        $proposals = array_filter($candidates, static fn (array $c): bool => MatchStatus::Proposed === ($c['stored']['status'] ?? null));
        if ([] === $proposals) {
            return 'not_linked';
        }

        return array_any($proposals, static fn (array $c): bool => ($c['stored']['conflict'] ?? false) && [] !== $c['rival_allocations']) ? 'conflict' : 'proposed';
    }
}
