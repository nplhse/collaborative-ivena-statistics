<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Contract\ReactionSummaryQueryInterface;
use App\Content\Application\Contract\ReactionTargetProviderInterface;
use App\Content\Application\Reaction\ReactionNotificationTarget;
use App\Content\Application\Reaction\ReactionService;
use App\Content\Application\Reaction\ReactionTarget;
use App\Content\Application\Reaction\ReactionTargetRegistry;
use App\Content\Application\Reaction\ReactionTargetUnavailableException;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Reaction bars (#467). Pages render only placeholders; the bars of all placeholders in view are loaded with one
 * request as Turbo Streams, so a page never waits for its reactions. Visitors see the counts, signed-in users also the
 * names and can react.
 *
 * @psalm-suppress UnusedClass
 */
final class ReactionController extends AbstractController
{
    /** Targets one request may load; the loader sends larger sets in several requests. */
    public const int MAX_TARGETS = 50;

    public const string CSRF_TOKEN_ID = 'reaction';

    public function __construct(
        private readonly ReactionTargetRegistry $targets,
        private readonly ReactionSummaryQueryInterface $summaries,
        private readonly ReactionService $reactions,
    ) {
    }

    #[Route('/reactions', name: 'app_reactions_show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $viewer = $this->viewer();
        $requested = ReactionTarget::listFromString($request->query->getString('targets'), self::MAX_TARGETS);

        return $this->renderStreams($request, $this->visibleTargets($requested, $viewer), $requested, $viewer);
    }

    /** The link of a reaction notification (#973): opens the content, or says that it is gone. */
    #[IsGranted('ROLE_USER')]
    #[Route(
        '/reactions/open/{targetType}/{targetId}',
        name: 'app_reaction_open',
        requirements: ['targetType' => '[a-z_]+', 'targetId' => Requirement::POSITIVE_INT],
        methods: ['GET'],
    )]
    public function open(string $targetType, int $targetId): RedirectResponse
    {
        $type = ReactionTargetType::tryFrom($targetType);
        $provider = $type instanceof ReactionTargetType ? $this->targets->get($type) : null;
        $target = $provider?->notificationTarget($targetId);

        if (!$provider instanceof ReactionTargetProviderInterface || !$target instanceof ReactionNotificationTarget || [] === $provider->visibleIds([$targetId], $this->viewer())) {
            $this->addFlash('warning', new TranslatableMessage('reaction.notification.unavailable', [], 'content'));

            return $this->redirectToRoute('app_notification_index');
        }

        $parameters = $target->routeParameters;
        if (null !== $target->fragment) {
            $parameters['_fragment'] = $target->fragment;
        }

        return $this->redirectToRoute($target->route, $parameters);
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        '/reactions/{targetType}/{targetId}',
        name: 'app_reaction_toggle',
        requirements: ['targetType' => '[a-z_]+', 'targetId' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function toggle(string $targetType, int $targetId, Request $request): Response
    {
        $viewer = $this->viewer();
        $type = ReactionTargetType::tryFrom($targetType);
        $reaction = ReactionType::tryFrom($request->request->getString('type'));
        if (!$viewer instanceof User || !$type instanceof ReactionTargetType || !$reaction instanceof ReactionType) {
            throw $this->createNotFoundException('Unknown reaction or target.');
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $target = new ReactionTarget($type, $targetId);
        try {
            $this->reactions->toggle($viewer, $target, $reaction);
        } catch (ReactionTargetUnavailableException) {
            throw $this->createNotFoundException('The reaction target is not available.');
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return $this->renderStreams($request, [$target], [$target], $viewer);
        }

        return $this->redirectBack($request);
    }

    /**
     * @param list<ReactionTarget> $visible   targets whose bars are rendered
     * @param list<ReactionTarget> $requested all requested targets; placeholders of the others are removed
     */
    private function renderStreams(Request $request, array $visible, array $requested, ?User $viewer): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
        $signedIn = $viewer instanceof User;

        $visibleKeys = array_map(static fn (ReactionTarget $target): string => $target->key(), $visible);
        $hidden = array_values(array_filter($requested, static fn (ReactionTarget $target): bool => !\in_array($target->key(), $visibleKeys, true)));

        $response = $this->render('@Content/reaction/streams.html.twig', [
            'summaries' => $this->summaries->summaries($visible, $viewer?->getId(), $signedIn),
            'hidden' => $hidden,
            'canReact' => $signedIn,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param list<ReactionTarget> $requested
     *
     * @return list<ReactionTarget>
     */
    private function visibleTargets(array $requested, ?User $viewer): array
    {
        $idsByType = [];
        foreach ($requested as $target) {
            $idsByType[$target->type->value][] = $target->id;
        }

        $visibleKeys = [];
        foreach ($idsByType as $type => $ids) {
            $provider = $this->targets->get(ReactionTargetType::from($type));
            foreach ($provider?->visibleIds($ids, $viewer) ?? [] as $id) {
                $visibleKeys[$type.':'.$id] = true;
            }
        }

        return array_values(array_filter($requested, static fn (ReactionTarget $target): bool => isset($visibleKeys[$target->key()])));
    }

    /** Without JavaScript the form posts normally; go back to the page it was on. */
    private function redirectBack(Request $request): RedirectResponse
    {
        $referer = $request->headers->get('referer');
        if (\is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost().'/')) {
            return $this->redirect($referer, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_default', [], Response::HTTP_SEE_OTHER);
    }

    private function viewer(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
