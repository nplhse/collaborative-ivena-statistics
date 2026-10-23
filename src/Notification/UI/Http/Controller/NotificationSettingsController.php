<?php

declare(strict_types=1);

namespace App\Notification\UI\Http\Controller;

use App\Notification\Application\Contract\NotificationPreferenceStoreInterface;
use App\Notification\Application\Preference\NotificationPreferenceMatrixBuilder;
use App\Notification\Application\Preference\NotificationPreferenceState;
use App\Notification\UI\Form\NotificationPreferencesType;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Settings page "Notifications" (ADR 016, "Preferences and resolution"). It lives in the
 * Notification context, which owns the preferences, and uses the settings layout of the User context.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class NotificationSettingsController extends AbstractController
{
    public function __construct(
        private readonly NotificationPreferenceStoreInterface $preferenceStore,
        private readonly NotificationPreferenceMatrixBuilder $matrixBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/settings/notifications', name: 'app_settings_notifications', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $userId = (int) $user->getId();
        $matrix = $this->matrixBuilder->build($user, $this->preferenceStore->load($userId));

        $preferences = [];
        foreach ($matrix->configurableControls() as $control) {
            $preferences[$control->fieldName()] = $control->state();
        }

        $form = $this->createForm(NotificationPreferencesType::class, [
            'preferences' => $preferences,
            'reminder' => ['receivesMonthlySubmissionReminder' => $user->receivesMonthlySubmissionReminder()],
        ], ['matrix' => $matrix]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{preferences: array<string, NotificationPreferenceState>, reminder: array{receivesMonthlySubmissionReminder?: bool}} $data */
            $data = $form->getData();

            $changes = [];
            foreach ($matrix->configurableControls() as $control) {
                $changes[] = $control->change($data['preferences'][$control->fieldName()] ?? NotificationPreferenceState::Default);
            }

            $this->preferenceStore->save($userId, $changes);

            $user->setReceivesMonthlySubmissionReminder($data['reminder']['receivesMonthlySubmissionReminder'] ?? false);
            $this->auditContext->beginIntent('user.settings.notifications_updated', []);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', new TranslatableMessage('flash.settings.notifications.updated', domain: 'user'));

            return $this->redirectToRoute('app_settings_notifications');
        }

        return $this->render('@Notification/settings/index.html.twig', [
            'matrix' => $matrix,
            'form' => $form,
        ]);
    }
}
