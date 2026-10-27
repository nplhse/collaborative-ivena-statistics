<?php

declare(strict_types=1);

namespace App\Notification\UI\Http\Controller;

use App\Notification\Application\Inbox\ClaimedToast;
use App\Notification\Application\Snapshot\NotificationSnapshotProvider;
use App\Notification\Domain\Enum\NotificationPriority;
use App\Notification\UI\ViewModel\NotificationToastGrouper;
use App\Notification\UI\ViewModel\NotificationView;
use App\Notification\UI\ViewModel\NotificationViewFactory;
use App\User\Domain\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Polling endpoint of active browser contexts (ADR 016, "Polling and initial rendering").
 *
 * The client sends the version it knows. Toasts are claimed independently of the version. Without
 * a change the answer is `204 No Content`; otherwise it carries the badge state and the claimed toasts.
 * The route is excluded from request analytics and Sentry tracing and never stores anything in the session.
 */
#[IsGranted('ROLE_USER')]
final class NotificationPollController extends AbstractController
{
    public const string ROUTE = 'app_notification_poll';

    /** Toasts shown at once; further ones are summarized in one toast. */
    public const int VISIBLE_TOASTS = 3;

    public function __construct(
        private readonly NotificationSnapshotProvider $snapshotProvider,
        private readonly NotificationViewFactory $viewFactory,
        private readonly TranslatorInterface $translator,
        private readonly NotificationToastGrouper $toastGrouper,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/notifications/poll', name: self::ROUTE, methods: ['GET'])]
    public function poll(
        Request $request,
        #[CurrentUser] User $user,
        #[Autowire(service: 'limiter.notification_poll')]
        RateLimiterFactory $notificationPollLimiter,
        #[MapQueryParameter] ?int $version = null,
    ): Response {
        $userId = (int) $user->getId();
        // Symfony would otherwise mark the response private and revalidating because the session was read.
        $request->attributes->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, true);

        $limit = $notificationPollLimiter->create('notification_poll_'.$userId)->consume();
        if (!$limit->isAccepted()) {
            return $this->noStore(new Response(null, Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()),
            ]));
        }

        $snapshot = $this->snapshotProvider->snapshot($userId, claimToasts: true);
        if ($snapshot->isUnchangedSince($version)) {
            return $this->noStore(new Response(null, Response::HTTP_NO_CONTENT));
        }

        $toasts = $this->toasts($user, $snapshot->toasts);

        return $this->noStore(new JsonResponse([
            'version' => $snapshot->version,
            'unreadCount' => $snapshot->unreadCount,
            'badgeLabel' => $this->translator->trans('notification.bell.label', ['count' => $snapshot->unreadCount], 'notification'),
            'toasts' => \array_slice($toasts, 0, self::VISIBLE_TOASTS),
            'more' => \count($toasts) > self::VISIBLE_TOASTS ? [
                'label' => $this->translator->trans('notification.toast.more', ['count' => \count($toasts) - self::VISIBLE_TOASTS], 'notification'),
                'url' => $this->generateUrl('app_notification_index', ['filter' => NotificationInboxController::FILTER_UNREAD]),
            ] : null,
        ]));
    }

    /**
     * Applies the inbox visibility rules (hidden entries are left out, redacted ones keep generic texts),
     * then collapses toasts of the same toast group into one.
     *
     * @param list<ClaimedToast> $claimed
     *
     * @return list<array<string, mixed>> id, title, message, url, severity, important
     */
    private function toasts(User $user, array $claimed): array
    {
        $byId = [];
        foreach ($claimed as $toast) {
            $byId[$toast->entry->id] = $toast;
        }

        $toasts = [];
        foreach ($this->viewFactory->build($user, array_map(static fn (ClaimedToast $toast): \App\Notification\Application\Inbox\InboxEntry => $toast->entry, $claimed)) as $view) {
            $claimedToast = $byId[$view->id] ?? null;
            if (!$claimedToast instanceof ClaimedToast) {
                continue;
            }
            $toasts[] = [
                'toast' => $this->toast($view),
                'type' => $claimedToast->type,
                'priority' => $claimedToast->priority,
            ];
        }

        return array_map(
            fn (array $entry): array => [
                ...$entry['toast'],
                'severity' => $this->severity($entry['priority']),
                'important' => \in_array($entry['priority'], [NotificationPriority::High, NotificationPriority::Urgent], true),
            ],
            $this->toastGrouper->group($toasts),
        );
    }

    /**
     * @return array{id: int, title: string, message: string, url: string|null}
     */
    private function toast(NotificationView $view): array
    {
        return [
            'id' => $view->id,
            'title' => $this->translator->trans($view->titleKey, $view->parameters, $view->translationDomain),
            'message' => $this->translator->trans($view->messageKey, $view->parameters, $view->translationDomain),
            'url' => $view->hasLink ? $this->generateUrl('app_notification_open', ['id' => $view->id]) : null,
        ];
    }

    private function severity(NotificationPriority $priority): string
    {
        return match ($priority) {
            NotificationPriority::Low => 'secondary',
            NotificationPriority::Normal => 'primary',
            NotificationPriority::High => 'warning',
            NotificationPriority::Urgent => 'danger',
        };
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
