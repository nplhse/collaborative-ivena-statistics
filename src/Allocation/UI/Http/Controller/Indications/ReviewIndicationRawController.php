<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Application\Indication\IndicationMatchSuggestionService;
use App\Allocation\Application\Indication\IndicationRawReviewNavigator;
use App\Allocation\Application\Indication\IndicationRawReviewService;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Domain\Enum\IndicationRawReviewWorklistSegment;
use App\Allocation\Infrastructure\Query\IndicationRawOccurrenceQuery;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Security\Voter\IndicationRawReviewVoter;
use App\Allocation\UI\Form\IndicationRawReviewType;
use App\Allocation\UI\Http\DTO\IndicationRawReviewWorklistQueryDTO;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(IndicationRawReviewVoter::VIEW)]
final class ReviewIndicationRawController extends AbstractController
{
    public function __construct(
        private readonly IndicationNormalizedRepository $normalizedRepository,
        private readonly IndicationRawReviewService $reviewService,
        private readonly IndicationRawReviewNavigator $navigator,
        private readonly IndicationRawOccurrenceQuery $occurrenceQuery,
        private readonly IndicationMatchSuggestionService $suggestionService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/explore/indication/raw/review/{id}', name: 'app_explore_indication_raw_review', methods: ['GET', 'POST'])]
    public function __invoke(
        IndicationRaw $raw,
        Request $request,
    ): Response {
        $context = $this->buildContextFromRequest($request);
        $form = $this->createForm(IndicationRawReviewType::class, $raw);
        $form->handleRequest($request);

        $datalist = $this->normalizedRepository->getDatalist();
        $initialLabel = $this->resolveInitialLabel($form->get('target')->getData())
            ?? $this->resolveInitialLabel($raw->getNormalized());

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }

            try {
                $redirect = $this->handleSubmit($form, $raw, $user, $context);
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('app_explore_indication_raw_review', [
                    'id' => $raw->getId(),
                    ...$this->contextQueryParams($context),
                ]);
            }

            return $redirect;
        }

        $user = $this->getUser();

        return $this->render('@Allocation/indications/review_raw.html.twig', [
            'raw' => $raw,
            'form' => $form->createView(),
            'datalist' => $datalist,
            'initial_label' => $initialLabel,
            'context' => $context,
            'occurrence_count' => $this->occurrenceQuery->fetchOccurrenceCount((int) $raw->getId()),
            'sample_allocations' => $this->occurrenceQuery->fetchSampleAllocations((int) $raw->getId()),
            'suggestions' => $this->suggestionService->suggest($raw),
            'other_user_activities' => $this->resolveOtherUserActivities(
                $raw,
                $user instanceof User ? $user : null,
            ),
            'can_edit_match' => $this->isGranted(IndicationRawReviewVoter::EDIT_MATCH),
            'can_review' => $this->isGranted(IndicationRawReviewVoter::REVIEW, $raw),
            'is_admin' => $this->isGranted(UserRole::ADMIN),
        ]);
    }

    #[Route('/explore/indication/raw/review/{id}/skip', name: 'app_explore_indication_raw_review_skip', methods: ['GET'])]
    public function skip(IndicationRaw $raw, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $context = $this->buildContextFromRequest($request);
        $next = $this->navigator->findNext($context, $raw->getId());

        if (!$next instanceof IndicationRaw || null === $next->getId()) {
            $this->addFlash('info', $this->translator->trans('flash.indication.review.no_more_open', [], 'allocation'));

            return $this->redirectToRoute('app_explore_indication_raw_review_worklist', $this->contextQueryParams($context));
        }

        return $this->redirectToRoute('app_explore_indication_raw_review', [
            'id' => $next->getId(),
            ...$this->contextQueryParams($context),
        ]);
    }

    #[Route('/explore/indication/raw/review/start/matching', name: 'app_explore_indication_raw_review_start_matching', methods: ['GET'])]
    #[IsGranted(IndicationRawReviewVoter::EDIT_MATCH)]
    public function startMatching(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToFirstInSegment(IndicationRawReviewWorklistSegment::Unreviewed);
    }

    #[Route('/explore/indication/raw/review/start/reviewing', name: 'app_explore_indication_raw_review_start_reviewing', methods: ['GET'])]
    #[IsGranted(IndicationRawReviewVoter::REVIEW)]
    public function startReviewing(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToFirstInSegment(IndicationRawReviewWorklistSegment::NeedsReview);
    }

    private function redirectToFirstInSegment(IndicationRawReviewWorklistSegment $segment): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $context = new IndicationRawReviewWorklistQueryDTO(segment: $segment);
        $first = $this->navigator->findNext($context, null);

        if (!$first instanceof IndicationRaw || null === $first->getId()) {
            $this->addFlash('info', $this->translator->trans('flash.indication.review.no_more_open', [], 'allocation'));

            return $this->redirectToRoute('app_explore_indication_raw_review_worklist', [
                'segment' => $segment->value,
            ]);
        }

        return $this->redirectToRoute('app_explore_indication_raw_review', [
            'id' => $first->getId(),
            'segment' => $segment->value,
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function handleSubmit(
        FormInterface $form,
        IndicationRaw $raw,
        User $user,
        IndicationRawReviewWorklistQueryDTO $context,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        $comment = $raw->getReviewComment();

        if ($this->isButtonClicked($form, 'propose')) {
            $target = $raw->getTarget();
            if (!$target instanceof IndicationNormalized) {
                throw new \InvalidArgumentException($this->translator->trans('flash.indication.review.target_required', [], 'allocation'));
            }
            $this->reviewService->proposeMatch($raw, $target, $user);
            $this->addFlash('success', $this->translator->trans('flash.indication.review.proposed', [], 'allocation'));

            return $this->redirectToNext($raw, $context);
        }

        if ($this->isButtonClicked($form, 'matchAndApprove')) {
            $target = $raw->getTarget();
            if (!$target instanceof IndicationNormalized) {
                throw new \InvalidArgumentException($this->translator->trans('flash.indication.review.target_required', [], 'allocation'));
            }
            $this->reviewService->matchAndApprove($raw, $target, $user, $comment);
            $this->addFlash('success', $this->translator->trans('flash.indication.review.matched_and_approved', [], 'allocation'));

            return $this->redirectToNext($raw, $context);
        }

        if ($this->isButtonClicked($form, 'approve')) {
            $this->reviewService->approveMatch($raw, $user, $comment);
            $this->addFlash('success', $this->translator->trans('flash.indication.review.approved', [], 'allocation'));

            return $this->redirectToNext($raw, $context);
        }

        if ($this->isButtonClicked($form, 'reject')) {
            $this->reviewService->rejectMatch($raw, $user, $comment);
            $this->addFlash('success', $this->translator->trans('flash.indication.review.rejected', [], 'allocation'));

            return $this->redirectToNext($raw, $context);
        }

        if ($this->isButtonClicked($form, 'notMatchable')) {
            $this->reviewService->reviewNotMatchable($raw, $user, $comment);
            $this->addFlash('success', $this->translator->trans('flash.indication.review.not_matchable', [], 'allocation'));

            return $this->redirectToNext($raw, $context);
        }

        if ($this->isButtonClicked($form, 'ignore')) {
            $this->reviewService->reviewIgnore($raw, $user, $comment);
            $this->addFlash('success', $this->translator->trans('flash.indication.review.ignored', [], 'allocation'));

            return $this->redirectToNext($raw, $context);
        }

        if ($this->isButtonClicked($form, 'reopen')) {
            $this->reviewService->reopenForReview($raw, $user, $comment);
            $this->addFlash('success', $this->translator->trans('flash.indication.review.reopened', [], 'allocation'));

            return $this->redirectToRoute('app_explore_indication_raw_review', [
                'id' => $raw->getId(),
                ...$this->contextQueryParams($context),
            ]);
        }

        if ($this->isButtonClicked($form, 'saveComment')) {
            $this->reviewService->saveComment($raw, $comment);
            $this->addFlash('success', $this->translator->trans('flash.indication.review.comment_saved', [], 'allocation'));

            return $this->redirectToRoute('app_explore_indication_raw_review', [
                'id' => $raw->getId(),
                ...$this->contextQueryParams($context),
            ]);
        }

        return $this->redirectToRoute('app_explore_indication_raw_review', [
            'id' => $raw->getId(),
            ...$this->contextQueryParams($context),
        ]);
    }

    private function redirectToNext(IndicationRaw $raw, IndicationRawReviewWorklistQueryDTO $context): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $next = $this->navigator->findNext($context, $raw->getId());
        if (!$next instanceof IndicationRaw || null === $next->getId()) {
            $this->addFlash('info', $this->translator->trans('flash.indication.review.no_more_open', [], 'allocation'));

            return $this->redirectToRoute('app_explore_indication_raw_review_worklist', $this->contextQueryParams($context));
        }

        return $this->redirectToRoute('app_explore_indication_raw_review', [
            'id' => $next->getId(),
            ...$this->contextQueryParams($context),
        ]);
    }

    private function buildContextFromRequest(Request $request): IndicationRawReviewWorklistQueryDTO
    {
        $segmentValue = $request->query->getString('segment', IndicationRawReviewWorklistSegment::Open->value);
        $segment = IndicationRawReviewWorklistSegment::tryFrom($segmentValue) ?? IndicationRawReviewWorklistSegment::Open;

        return new IndicationRawReviewWorklistQueryDTO(
            page: max(1, $request->query->getInt('page', 1)),
            limit: min(100, max(1, $request->query->getInt('limit', 25))),
            orderBy: 'desc' === $request->query->getString('orderBy', 'asc') ? 'desc' : 'asc',
            sortBy: $request->query->getString('sortBy', 'createdAt'),
            search: $request->query->get('search'),
            segment: $segment,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private function contextQueryParams(IndicationRawReviewWorklistQueryDTO $context): array
    {
        return array_filter([
            'segment' => $context->segment->value,
            'page' => $context->page,
            'limit' => $context->limit,
            'orderBy' => $context->orderBy,
            'sortBy' => $context->sortBy,
            'search' => $context->search,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    private function resolveInitialLabel(mixed $initialId): ?string
    {
        if (is_string($initialId) && '' !== $initialId && ctype_digit($initialId)) {
            return $this->normalizedRepository->getDatalistLabelById((int) $initialId);
        }

        if ($initialId instanceof IndicationNormalized) {
            $id = $initialId->getId();
            if (null !== $id) {
                return $this->normalizedRepository->getDatalistLabelById($id);
            }
        }

        return null;
    }

    /**
     * @return list<array{label: string, user: string, at: \DateTimeImmutable}>
     */
    private function resolveOtherUserActivities(IndicationRaw $raw, ?User $currentUser): array
    {
        $currentUserId = $currentUser?->getId();
        $activities = [];

        $firstMatcher = $raw->getFirstMatchedBy();
        $firstMatchedAt = $raw->getFirstMatchedAt();
        $reviewer = $raw->getReviewedBy();
        $reviewedAt = $raw->getReviewedAt();

        $firstMatcherId = $firstMatcher?->getId();
        $reviewerId = $reviewer?->getId();
        $sameActor = null !== $firstMatcherId && $firstMatcherId === $reviewerId;

        if (
            $firstMatcher instanceof User
            && $firstMatcherId !== $currentUserId
            && $firstMatchedAt instanceof \DateTimeImmutable
            && (!$sameActor || IndicationRawReviewStatus::NeedsReview === $raw->getReviewStatus())
        ) {
            $activities[] = [
                'label' => 'label.indication.match_proposed_by',
                'user' => (string) $firstMatcher,
                'at' => $firstMatchedAt,
            ];
        }

        if (
            $reviewer instanceof User
            && $reviewerId !== $currentUserId
            && $reviewedAt instanceof \DateTimeImmutable
            && (!$sameActor || IndicationRawReviewStatus::NeedsReview !== $raw->getReviewStatus())
        ) {
            $label = match ($raw->getReviewStatus()) {
                IndicationRawReviewStatus::Unreviewed => 'label.indication.review_rejected_by',
                IndicationRawReviewStatus::Matched => 'label.indication.review_approved_by',
                default => 'label.indication.reviewed_by',
            };

            $activities[] = [
                'label' => $label,
                'user' => (string) $reviewer,
                'at' => $reviewedAt,
            ];
        }

        return $activities;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function isButtonClicked(FormInterface $form, string $name): bool
    {
        $button = $form->get($name);

        return $button instanceof ClickableInterface && $button->isClicked();
    }
}
