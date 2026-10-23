<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Notification\Application\Contract\NotificationEmailCustomizerInterface;
use App\Notification\Application\Delivery\NotificationEmailContent;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The email of `admin.import_failed` keeps the former admin mail layout: import, hospital, row count and
 * the translated reason. It is built from the stored parameters only.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationEmailCustomizerInterface.
 */
final readonly class AdminImportFailedEmailCustomizer implements NotificationEmailCustomizerInterface
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function supports(string $type): bool
    {
        return AdminImportFailedNotification::TYPE === $type;
    }

    #[\Override]
    public function customize(NotificationEmailContent $content, User $recipient): void
    {
        $parameters = $content->parameters;

        $content->htmlTemplate = '@Import/email/admin_import_failed.html.twig';
        $content->context = [
            'title' => $content->context['title'] ?? $content->subject,
            'inboxUrl' => $content->context['inboxUrl'] ?? null,
            'importDetailUrl' => $content->context['actionUrl'] ?? null,
            'importName' => $parameters['importName'] ?? '',
            'hospitalName' => $parameters['hospitalName'] ?? '',
            'rowCount' => $parameters['rowsTotal'] ?? null,
            'reason' => $this->translator->trans(
                'admin.import_failed.reason',
                ['reason' => $parameters['reason'] ?? 'other'],
                'notification',
                $content->locale,
            ),
        ];
    }
}
