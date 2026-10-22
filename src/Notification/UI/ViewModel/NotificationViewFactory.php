<?php

declare(strict_types=1);

namespace App\Notification\UI\ViewModel;

use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Application\Inbox\NotificationVisibility;
use App\Notification\Application\Inbox\NotificationVisibilityResolver;
use App\User\Domain\Entity\User;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns stored entries into views for one viewer: applies the visibility rules and resolves the
 * contextual link from route name and parameters.
 */
final readonly class NotificationViewFactory
{
    public const string REDACTED_DOMAIN = 'notification';

    public const string REDACTED_TITLE_KEY = 'notification.redacted.title';

    public const string REDACTED_MESSAGE_KEY = 'notification.redacted.message';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NotificationVisibilityResolver $visibilityResolver,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<InboxEntry> $entries
     *
     * @return list<NotificationView> hidden entries are left out
     */
    public function build(User $viewer, array $entries): array
    {
        $visibility = $this->visibilityResolver->resolve($viewer, $entries);
        $views = [];

        foreach ($entries as $entry) {
            $views[] = match ($visibility[$entry->id] ?? NotificationVisibility::Hidden) {
                NotificationVisibility::Visible => new NotificationView(
                    id: $entry->id,
                    translationDomain: $entry->translationDomain,
                    titleKey: $entry->titleKey,
                    messageKey: $entry->messageKey,
                    parameters: $entry->parameters,
                    hasLink: null !== $this->actionUrl($entry),
                    isRead: $entry->isRead(),
                    isRedacted: false,
                    createdAt: $entry->createdAt,
                ),
                NotificationVisibility::Redacted => new NotificationView(
                    id: $entry->id,
                    translationDomain: self::REDACTED_DOMAIN,
                    titleKey: self::REDACTED_TITLE_KEY,
                    messageKey: self::REDACTED_MESSAGE_KEY,
                    parameters: [],
                    hasLink: false,
                    isRead: $entry->isRead(),
                    isRedacted: true,
                    createdAt: $entry->createdAt,
                ),
                NotificationVisibility::Hidden => null,
            };
        }

        return array_values(array_filter($views));
    }

    /**
     * The target of an entry for this viewer, or null when it has none, is redacted or hidden,
     * or its route no longer exists.
     */
    public function targetUrl(User $viewer, InboxEntry $entry): ?string
    {
        $visibility = $this->visibilityResolver->resolve($viewer, [$entry])[$entry->id] ?? NotificationVisibility::Hidden;

        return NotificationVisibility::Visible === $visibility ? $this->actionUrl($entry) : null;
    }

    private function actionUrl(InboxEntry $entry): ?string
    {
        if (null === $entry->actionRoute) {
            return null;
        }

        try {
            return $this->urlGenerator->generate($entry->actionRoute, $entry->actionParameters);
        } catch (RoutingException) {
            return null;
        }
    }
}
