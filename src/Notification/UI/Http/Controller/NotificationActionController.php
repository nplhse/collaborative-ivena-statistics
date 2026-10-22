<?php

declare(strict_types=1);

namespace App\Notification\UI\Http\Controller;

use App\Notification\Application\Contract\NotificationInboxQueryInterface;
use App\Notification\Application\Contract\NotificationInboxWriterInterface;
use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\UI\Http\SafeTargetPath;
use App\Notification\UI\ViewModel\NotificationViewFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read and removal actions on the viewer's own notifications. Entries of other users are not found.
 */
#[IsGranted('ROLE_USER')]
final class NotificationActionController extends AbstractController
{
    public const string CSRF_READ = 'notification_read';

    public const string CSRF_READ_ALL = 'notification_read_all';

    public const string CSRF_REMOVE = 'notification_remove';

    public function __construct(
        private readonly NotificationInboxQueryInterface $inboxQuery,
        private readonly NotificationInboxWriterInterface $inboxWriter,
        private readonly NotificationViewFactory $viewFactory,
    ) {
    }

    /**
     * Opens the context of an entry and marks it as read. The target is generated from the stored
     * route, so this never redirects outside the application.
     */
    #[Route('/notifications/{id}/open', name: 'app_notification_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(int $id, #[CurrentUser] User $user): RedirectResponse
    {
        $entry = $this->ownEntry($user, $id);
        $this->inboxWriter->markRead($this->userId($user), $id);

        return $this->redirect($this->viewFactory->targetUrl($user, $entry) ?? $this->generateUrl('app_notification_index'));
    }

    #[Route('/notifications/{id}/read', name: 'app_notification_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function read(int $id, Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf(self::CSRF_READ, $request);
        $this->ownEntry($user, $id);
        $this->inboxWriter->markRead($this->userId($user), $id);

        return $this->redirectBack($request);
    }

    #[Route('/notifications/read-all', name: 'app_notification_read_all', methods: ['POST'])]
    public function readAll(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf(self::CSRF_READ_ALL, $request);
        $this->inboxWriter->markAllRead($this->userId($user));

        return $this->redirectBack($request);
    }

    #[Route('/notifications/{id}/remove', name: 'app_notification_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(int $id, Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf(self::CSRF_REMOVE, $request);
        $this->ownEntry($user, $id);
        $this->inboxWriter->remove($this->userId($user), $id);

        return $this->redirectBack($request);
    }

    private function ownEntry(User $user, int $id): InboxEntry
    {
        return $this->inboxQuery->find($this->userId($user), $id) ?? throw new NotFoundHttpException();
    }

    private function assertCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Returns to the page the action was triggered from. Only same-site paths are accepted.
     */
    private function redirectBack(Request $request): RedirectResponse
    {
        return $this->redirect(SafeTargetPath::sanitize($request->request->getString('_target')) ?? $this->generateUrl('app_notification_index'));
    }

    private function userId(User $user): int
    {
        return (int) $user->getId();
    }
}
