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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Read and removal actions on the viewer's own notifications. Entries of other users are not found.
 */
#[IsGranted('ROLE_USER')]
final class NotificationActionController extends AbstractController
{
    public const string CSRF_READ = 'notification_read';

    public const string CSRF_READ_ALL = 'notification_read_all';

    public const string CSRF_REMOVE = 'notification_remove';

    public const string CSRF_REMOVE_ALL = 'notification_remove_all';

    public const string CSRF_BULK = 'notification_bulk';

    public const string BULK_READ = 'read';

    public const string BULK_REMOVE = 'remove';

    /** Upper limit for one bulk request; a page shows 25 entries. */
    private const int BULK_MAX_IDS = 100;

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
    public function open(int $id, #[CurrentUser] User $user, Request $request): Response
    {
        // Opening marks the entry as read, so a prefetch (Turbo on hover, browser speculation) must not open it.
        if ($this->isPrefetch($request)) {
            return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
        }

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

    /**
     * Applies "read" or "remove" to the selected entries. Ids of other users are ignored by the writer.
     */
    #[Route('/notifications/bulk', name: 'app_notification_bulk', methods: ['POST'])]
    public function bulk(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf(self::CSRF_BULK, $request);

        $ids = $this->selectedIds($request);
        if ([] === $ids) {
            $this->addFlash('warning', new TranslatableMessage('notification.flash.none_selected', domain: 'notification'));

            return $this->redirectBack($request);
        }

        $action = $request->request->getString('bulk_action');
        match ($action) {
            self::BULK_READ => $this->addFlash('success', new TranslatableMessage(
                'notification.flash.marked_read',
                ['count' => $this->inboxWriter->markReadMany($this->userId($user), $ids)],
                'notification',
            )),
            self::BULK_REMOVE => $this->addFlash('success', new TranslatableMessage(
                'notification.flash.removed',
                ['count' => $this->inboxWriter->removeMany($this->userId($user), $ids)],
                'notification',
            )),
            default => throw new BadRequestHttpException(\sprintf('Unknown bulk action "%s".', $action)),
        };

        return $this->redirectBack($request);
    }

    /**
     * Removes all entries, or only the unread ones when the overview is filtered to unread entries.
     */
    #[Route('/notifications/remove-all', name: 'app_notification_remove_all', methods: ['POST'])]
    public function removeAll(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf(self::CSRF_REMOVE_ALL, $request);
        $unreadOnly = NotificationInboxController::FILTER_UNREAD === $request->query->getString('filter');

        $this->addFlash('success', new TranslatableMessage(
            'notification.flash.removed',
            ['count' => $this->inboxWriter->removeAll($this->userId($user), $unreadOnly)],
            'notification',
        ));

        return $this->redirectToRoute('app_notification_index', $unreadOnly ? ['filter' => NotificationInboxController::FILTER_UNREAD] : []);
    }

    /**
     * @return list<int>
     */
    private function selectedIds(Request $request): array
    {
        $raw = $request->request->all('ids');
        if (\count($raw) > self::BULK_MAX_IDS) {
            throw new BadRequestHttpException(\sprintf('At most %d notifications can be changed at once.', self::BULK_MAX_IDS));
        }

        $ids = [];
        foreach ($raw as $value) {
            if (\is_string($value) && 1 === preg_match('/^[1-9]\d{0,9}$/', $value)) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
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

    private function isPrefetch(Request $request): bool
    {
        return array_any(['X-Sec-Purpose', 'Sec-Purpose', 'Purpose'], fn (string $header): bool => str_contains(strtolower((string) $request->headers->get($header)), 'prefetch'));
    }
}
