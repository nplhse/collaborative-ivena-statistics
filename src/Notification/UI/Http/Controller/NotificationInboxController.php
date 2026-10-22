<?php

declare(strict_types=1);

namespace App\Notification\UI\Http\Controller;

use App\Notification\Application\Contract\NotificationInboxQueryInterface;
use App\Notification\Application\Inbox\InboxCursor;
use App\Notification\Application\Inbox\InboxPage;
use App\Notification\UI\Http\SafeTargetPath;
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
    /** Value of the "filter" query parameter that limits the overview to unread entries. */
    public const string FILTER_UNREAD = 'unread';

    public function __construct(
        private readonly NotificationInboxQueryInterface $inboxQuery,
        private readonly NotificationViewFactory $viewFactory,
    ) {
    }

    /**
     * Overview with keyset paging: "before" continues with older entries, "after" with newer ones.
     * Invalid cursors start from the newest entries.
     */
    #[Route('/notifications', name: 'app_notification_index', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $filter = null,
        #[MapQueryParameter] ?string $before = null,
        #[MapQueryParameter] ?string $after = null,
    ): Response {
        $userId = (int) $user->getId();
        $unreadOnly = self::FILTER_UNREAD === $filter;
        $beforeCursor = InboxCursor::tryDecode($before);
        $afterCursor = $beforeCursor instanceof InboxCursor ? null : InboxCursor::tryDecode($after);

        $page = $this->inboxQuery->page($userId, $beforeCursor, $afterCursor, InboxPage::OVERVIEW_PAGE_SIZE, $unreadOnly);
        $filterParams = $unreadOnly ? ['filter' => self::FILTER_UNREAD] : [];
        $positionParams = match (true) {
            $beforeCursor instanceof InboxCursor => ['before' => $before],
            $afterCursor instanceof InboxCursor => ['after' => $after],
            default => [],
        };

        return $this->render('@Notification/inbox/index.html.twig', [
            'state' => $this->inboxQuery->state($userId),
            'notifications' => $this->viewFactory->build($user, $page->entries),
            'unreadOnly' => $unreadOnly,
            'isFirstPage' => [] === $positionParams,
            'filterParams' => $filterParams,
            'currentPath' => $this->generateUrl('app_notification_index', [...$filterParams, ...$positionParams]),
            'olderPath' => $page->olderCursor instanceof InboxCursor ? $this->generateUrl('app_notification_index', [...$filterParams, 'before' => $page->olderCursor->encode()]) : null,
            'newerPath' => $page->newerCursor instanceof InboxCursor ? $this->generateUrl('app_notification_index', [...$filterParams, 'after' => $page->newerCursor->encode()]) : null,
        ]);
    }

    #[Route('/notifications/dropdown', name: 'app_notification_dropdown', methods: ['GET'])]
    public function dropdown(#[CurrentUser] User $user, #[MapQueryParameter] ?string $target = null): Response
    {
        $userId = (int) $user->getId();

        return $this->render('@Notification/inbox/_dropdown.html.twig', [
            'target' => SafeTargetPath::sanitize($target) ?? $this->generateUrl('app_notification_index'),
            'notifications' => $this->viewFactory->build($user, $this->inboxQuery->latest($userId, InboxPage::DROPDOWN_SIZE)),
            'state' => $this->inboxQuery->state($userId),
        ]);
    }
}
