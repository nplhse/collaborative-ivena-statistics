<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Validation;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchValidationReaderInterface;
use App\Encounter\Application\Contract\MatchValidatorInterface;
use App\Encounter\Application\Review\MatchDecisionException;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Application\Validation\ValidationLinkRefusedException;
use App\Encounter\Application\Validation\ValidationRecordResult;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
use App\Encounter\Domain\Enum\ValidationVerdict;
use App\Encounter\UI\Validation\ValidationItemFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * One validation item (#1057): one question, one decision.
 *
 * A verdict comes from the decision buttons (`verdict`) or from an option row (`partner`: link this visit
 * or allocation; on a pair "wrong, another visit fits", otherwise "missed"). Verdict and links are written
 * together; then the next item without verdict opens. "Skip" is a plain link to that next open item: it
 * records nothing and leaves this item open.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowValidationItemController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'encounter_validation_verdict';

    public function __construct(
        private readonly MatchValidationReaderInterface $validation,
        private readonly MatchValidatorInterface $validator,
        private readonly ValidationItemFactory $factory,
    ) {
    }

    #[Route('/tools/encounter-review/{id}/validation/{sampleId}/items/{itemId}', name: 'app_tools_encounter_validation_item', requirements: ['id' => '\d+', 'sampleId' => '\d+', 'itemId' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $sampleId, int $itemId, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        $hospitalId = (int) $hospital->getId();

        $sample = $this->validation->sample($hospitalId, $sampleId) ?? throw $this->createNotFoundException('Sample not found in this hospital.');
        $item = $this->validation->item($sample, $itemId) ?? throw $this->createNotFoundException('Item not found in this sample.');
        $page = $this->factory->create($item);

        if ($request->isMethod(Request::METHOD_POST)) {
            return $this->decide($request, $sample, $item, $page, $user);
        }

        $next = $this->nextOpen($sample, $item);

        return $this->render('@Encounter/validation/item.html.twig', [
            'hospital' => $hospital,
            'sample' => $sample,
            'item' => $item,
            'page' => $page,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'skip_url' => $next instanceof MatchValidationItem
                ? $this->generateUrl('app_tools_encounter_validation_item', ['id' => $hospitalId, 'sampleId' => $sample->getId(), 'itemId' => $next->getId()])
                : $this->generateUrl('app_tools_encounter_validation_sample', ['id' => $hospitalId, 'sampleId' => $sample->getId()]),
        ]);
    }

    /**
     * @param array<string, mixed>|null $page
     */
    private function decide(Request $request, MatchValidationSample $sample, MatchValidationItem $item, ?array $page, User $user): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $hospitalId = (int) $sample->getHospital()->getId();
        $stratum = $item->getStratum();
        $partner = $request->request->has('partner') ? $request->request->getInt('partner') : null;
        /** @var list<int> $partnerIds */
        $partnerIds = $page['partner_ids'] ?? [];
        if (null !== $partner && !\in_array($partner, $partnerIds, true)) {
            throw new BadRequestHttpException('Not an option of this item.');
        }

        $verdict = null !== $partner
            ? ($stratum->isPair() ? ValidationVerdict::Wrong : ValidationVerdict::Missed)
            : ValidationVerdict::tryFrom($request->request->getString('verdict'));
        // "Missed" always names the partner: the options are the candidates.
        if (!$verdict instanceof ValidationVerdict || !\in_array($verdict, $stratum->verdicts(), true) || (ValidationVerdict::Missed === $verdict && null === $partner)) {
            throw new BadRequestHttpException('Not a verdict of this item.');
        }

        $itemUrl = $this->generateUrl('app_tools_encounter_validation_item', ['id' => $hospitalId, 'sampleId' => $sample->getId(), 'itemId' => $item->getId()]);
        try {
            $result = $this->validator->record($item, $verdict, $user, $partner);
        } catch (ValidationLinkRefusedException $exception) {
            $this->addFlash('warning', new TranslatableMessage('encounter.validation.flash.link_refused', ['allocation' => $exception->allocationId], 'encounter'));

            return $this->redirect($itemUrl);
        } catch (MatchDecisionException $exception) {
            $this->addFlash('warning', new TranslatableMessage($exception->translationKey(), domain: 'encounter'));

            return $this->redirect($itemUrl);
        }

        $flash = ValidationRecordResult::RECORDED === $result->outcome ? 'recorded' : 'recorded_'.$result->outcome;
        $this->addFlash('success', new TranslatableMessage('encounter.validation.flash.'.$flash, domain: 'encounter'));

        $next = $this->nextOpen($sample, $item);
        if (!$next instanceof MatchValidationItem) {
            $this->addFlash('info', new TranslatableMessage('encounter.validation.flash.sample_done', domain: 'encounter'));

            return $this->redirectToRoute('app_tools_encounter_validation_sample', ['id' => $hospitalId, 'sampleId' => $sample->getId()]);
        }

        return $this->redirectToRoute('app_tools_encounter_validation_item', ['id' => $hospitalId, 'sampleId' => $sample->getId(), 'itemId' => $next->getId()]);
    }

    /**
     * The next item without verdict after this one, wrapping around to the start; never the item itself.
     */
    private function nextOpen(MatchValidationSample $sample, MatchValidationItem $item): ?MatchValidationItem
    {
        $open = array_filter(
            $this->validation->items($sample),
            static fn (MatchValidationItem $other): bool => !$other->getVerdict() instanceof ValidationVerdict && $other->getId() !== $item->getId(),
        );

        return array_find($open, static fn (MatchValidationItem $other): bool => $other->getPosition() > $item->getPosition()) ?? (array_values($open)[0] ?? null);
    }
}
