<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\User;

use App\User\Application\LoginFailure\LoginFailureTrend;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Query\LoginFailureDayQuery;
use App\User\Infrastructure\Query\LoginFailureOverviewQuery;
use App\User\Infrastructure\Security\AccountLock\AccountLock;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Failed sign-ins (#1487): today's counts, the last 30 days with flagged days, and the accounts locked right now (#1762)
 * with "Lift lock".
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/operations/sign-in-security', name: 'operations_sign_in_security')]
final class SignInSecurityController extends AbstractController
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly LoginFailureOverviewQuery $overview,
        private readonly LoginFailureDayQuery $dayQuery,
        private readonly AccountLock $accountLock,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.security.account_lock.enabled')]
        private readonly bool $lockEnabled,
    ) {
    }

    #[AdminRoute(path: '/', name: 'index', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);

        return $this->render('@Admin/operations/sign_in_security/index.html.twig', [
            'lock_enabled' => $this->lockEnabled,
            'today' => $this->dayQuery->countDay($today, $today->modify('+1 day')),
            'daily' => $this->overview->dailySince($today->modify(\sprintf('-%d days', LoginFailureTrend::DAYS))),
            'locked_accounts' => $this->overview->lockedAccounts($now),
        ]);
    }

    #[AdminRoute(path: '/{id}/unlock', name: 'unlock', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function unlock(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('sign_in_unlock_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->entityManager->find(User::class, $id);
        if (!$user instanceof User) {
            throw new NotFoundHttpException('User not found.');
        }

        $this->accountLock->unlock($user);
        $this->addFlash('success', new TranslatableMessage('sign_in_security.flash.unlocked', ['username' => $user->getUserIdentifier()], 'admin'));

        return $this->redirect($this->adminUrlGenerator->setRoute('app_admin_dashboard_operations_sign_in_security_index')->generateUrl());
    }
}
