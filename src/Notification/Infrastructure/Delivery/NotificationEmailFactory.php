<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Contract\NotificationEmailCustomizerInterface;
use App\Notification\Application\Delivery\NotificationEmailContent;
use App\Notification\Application\Inbox\InboxEntry;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\User\Domain\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the email of one delivery from the stored entry, in the recipient's locale: the generic
 * notification email (title, message, link) unless a customizer of the owning context takes over.
 * The delivery id travels in a header, so the mail events can update the delivery.
 */
final readonly class NotificationEmailFactory
{
    public const string DELIVERY_ID_HEADER = 'X-Notification-Delivery-Id';

    public const string DEFAULT_TEMPLATE = '@Notification/email/notification.html.twig';

    /**
     * @param iterable<NotificationEmailCustomizerInterface> $customizers
     *
     * @psalm-suppress PossiblyUnusedMethod Wired by the container.
     */
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private MailConfig $mailConfig,
        private LocaleResolver $localeResolver,
        #[AutowireIterator(NotificationEmailCustomizerInterface::TAG)]
        private iterable $customizers,
    ) {
    }

    public function create(int $deliveryId, string $type, InboxEntry $entry, User $recipient): TemplatedEmail
    {
        $locale = $this->localeResolver->resolveForUser($recipient);
        $title = $this->translator->trans($entry->titleKey, $entry->parameters, $entry->translationDomain, $locale);

        $content = new NotificationEmailContent(
            type: $type,
            locale: $locale,
            parameters: $entry->parameters,
            subject: $title,
            htmlTemplate: self::DEFAULT_TEMPLATE,
            context: [
                'title' => $title,
                'message' => $this->translator->trans($entry->messageKey, $entry->parameters, $entry->translationDomain, $locale),
                'actionUrl' => $this->actionUrl($entry),
                'inboxUrl' => $this->urlGenerator->generate('app_notification_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        );

        foreach ($this->customizers as $customizer) {
            if ($customizer->supports($type)) {
                $customizer->customize($content, $recipient);
            }
        }

        $email = new TemplatedEmail()
            ->from(new Address($this->mailConfig->fromEmail, $this->mailConfig->fromName))
            ->locale($locale)
            ->subject($content->subject)
            ->htmlTemplate($content->htmlTemplate)
            ->context($content->context);

        $replyTo = $this->mailConfig->replyTo();
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        $email->getHeaders()->addTextHeader(self::DELIVERY_ID_HEADER, (string) $deliveryId);

        return $email;
    }

    private function actionUrl(InboxEntry $entry): ?string
    {
        if (null === $entry->actionRoute) {
            return null;
        }

        try {
            return $this->urlGenerator->generate($entry->actionRoute, $entry->actionParameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RoutingException) {
            return null;
        }
    }
}
