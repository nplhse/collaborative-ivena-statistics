<?php

declare(strict_types=1);

namespace App\Notification\UI\Http\Controller;

use App\Notification\Application\Contract\NotificationInboxQueryInterface;
use App\Notification\Application\Inbox\InboxCursor;
use App\Notification\Application\Inbox\InboxPage;
use App\Notification\UI\Http\SafeTargetPath;
use App\Notification\UI\ViewModel\NotificationView;
use App\Notification\UI\ViewModel\NotificationViewFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class NotificationInboxController extends AbstractController
{
    public const string FIRST_FRAME_ID = 'notification-inbox';

    public const string NEXT_FRAME_PREFIX = 'notification-inbox-after-';

    public function __construct(
        private readonly NotificationInboxQueryInterface $inboxQuery,
        private readonly NotificationViewFactory $viewFactory,
    ) {
    }

    #[Route('/notifications', name: 'app_notification_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('@Notification/inbox/index.html.twig', [
            'state' => $this->inboxQuery->state($this->userId($user)),
            ...$this->pageContext($user, null, self::FIRST_FRAME_ID),
        ]);
    }

    #[Route('/notifications/page', name: 'app_notification_page', methods: ['GET'])]
    public function page(#[CurrentUser] User $user, #[MapQueryParameter] ?string $cursor = null): Response
    {
        $decoded = InboxCursor::tryDecode($cursor);
        $frameId = $decoded instanceof InboxCursor && null !== $cursor ? self::NEXT_FRAME_PREFIX.$cursor : self::FIRST_FRAME_ID;

        return $this->render('@Notification/inbox/_page.html.twig', $this->pageContext($user, $decoded, $frameId));
    }

    #[Route('/notifications/dropdown', name: 'app_notification_dropdown', methods: ['GET'])]
    public function dropdown(#[CurrentUser] User $user, #[MapQueryParameter] ?string $target = null): Response
    {
        $userId = $this->userId($user);

        return $this->render('@Notification/inbox/_dropdown.html.twig', [
            'target' => SafeTargetPath::sanitize($target) ?? $this->generateUrl('app_notification_index'),
            'notifications' => $this->viewFactory->build($user, $this->inboxQuery->latest($userId, InboxPage::DROPDOWN_SIZE)),
            'state' => $this->inboxQuery->state($userId),
        ]);
    }

    /**
     * @return array{notifications: list<NotificationView>, frameId: string, nextCursor: ?string}
     */
    private function pageContext(User $user, ?InboxCursor $cursor, string $frameId): array
    {
        $page = $this->inboxQuery->page($this->userId($user), $cursor, InboxPage::OVERVIEW_PAGE_SIZE);

        return [
            'notifications' => $this->viewFactory->build($user, $page->entries),
            'frameId' => $frameId,
            'nextCursor' => $page->nextCursor?->encode(),
        ];
    }

    private function userId(User $user): int
    {
        return (int) $user->getId();
    }
}
