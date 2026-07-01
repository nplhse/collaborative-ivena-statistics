<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\User;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[IsGranted('ROLE_ADMIN')]
final class GrantParticipantController extends AbstractController
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UriSigner $uriSigner,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/admin/users/{id}/grant-participant', name: 'app_admin_user_grant_participant', requirements: ['id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(int $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (!$this->uriSigner->check($request->getUri())) {
            throw $this->createAccessDeniedException('Invalid or expired link.');
        }

        $expires = $request->query->get('expires');
        if (!\is_string($expires) || !ctype_digit($expires) || (int) $expires < time()) {
            throw $this->createAccessDeniedException('Invalid or expired link.');
        }

        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException('User not found.');
        }

        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => UserRole::USER !== $role,
        ));
        if (!\in_array(UserRole::PARTICIPANT, $roles, true)) {
            $roles[] = UserRole::PARTICIPANT;
            $user->setRoles($roles);

            $this->auditContext->beginIntent('user.admin.grant_participant', ['user_id' => $id]);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', new TranslatableMessage('flash.admin.user.grant_participant.success', domain: 'admin'));
        } else {
            $this->addFlash('info', new TranslatableMessage('flash.admin.user.grant_participant.already', domain: 'admin'));
        }

        return $this->redirect(
            $this->adminUrlGenerator
                ->setController(UserCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($id)
                ->generateUrl(),
        );
    }
}
