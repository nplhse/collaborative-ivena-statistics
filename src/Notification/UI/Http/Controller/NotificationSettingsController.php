<?php

declare(strict_types=1);

namespace App\Notification\UI\Http\Controller;

use App\Notification\Application\Contract\NotificationPreferenceStoreInterface;
use App\Notification\Application\Preference\NotificationPreferenceMatrixBuilder;
use App\Notification\UI\Form\NotificationPreferencesType;
use App\User\Domain\Entity\User;
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
    ) {
    }

    #[Route('/settings/notifications', name: 'app_settings_notifications', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $userId = (int) $user->getId();
        $matrix = $this->matrixBuilder->build($user, $this->preferenceStore->load($userId));

        $preferences = [];
        foreach ($matrix->configurableControls() as $control) {
            $preferences[$control->fieldName()] = $control->isChecked();
        }

        $form = $this->createForm(NotificationPreferencesType::class, ['preferences' => $preferences], ['matrix' => $matrix]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{preferences: array<string, bool>} $data */
            $data = $form->getData();

            $this->preferenceStore->save($userId, $matrix->changesFor($data['preferences']));

            $this->addFlash('success', new TranslatableMessage('flash.settings.notifications.updated', domain: 'user'));

            return $this->redirectToRoute('app_settings_notifications');
        }

        return $this->render('@Notification/settings/index.html.twig', [
            'matrix' => $matrix,
            'form' => $form,
        ]);
    }
}
