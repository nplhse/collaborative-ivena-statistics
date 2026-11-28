<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\TwoFactor\BackupCodeGenerator;
use App\User\Infrastructure\Security\TwoFactor\PendingTotpSetup;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Two-factor authentication with an authenticator app in the settings (#1357, ADR 040): set it up with a QR code and a
 * first code, renew the backup codes, switch it off. Changes need a fully authenticated session and a current code.
 *
 * @psalm-suppress UnusedClass
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class TwoFactorSettingsController extends AbstractController
{
    /** Encrypted secret of a setup that is not confirmed yet, so reloading the page keeps the QR code. */
    private const string PENDING_SECRET_SESSION_KEY = '_two_factor_pending_secret';

    /** Backup codes shown once on the next page, then removed from the session. */
    private const string NEW_BACKUP_CODES_SESSION_KEY = '_two_factor_new_backup_codes';

    public function __construct(
        private readonly TwoFactorAvailability $availability,
        private readonly TwoFactorSecretCipher $cipher,
        private readonly TotpAuthenticatorInterface $totpAuthenticator,
        private readonly BackupCodeGenerator $backupCodeGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContext $auditContext,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.two_factor_settings_code')]
        private readonly RateLimiterFactory $codeLimiter,
        #[Autowire(param: 'app.two_factor.backup_code_hash_cost')]
        private readonly ?int $backupCodeHashCost = null,
    ) {
    }

    #[Route('/settings/two-factor', name: 'app_settings_two_factor', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $user = $this->requireAvailableFeature();

        if ($user->isTotpAuthenticationEnabled()) {
            return $this->render('@User/settings/two_factor.html.twig', [
                'enabled' => true,
                'required' => $this->availability->isRequiredFor($user),
                'enabledAt' => $user->getTotpEnabledAt(),
                'backupCodesLeft' => $user->countBackupCodes(),
                'backupCodesTotal' => BackupCodeGenerator::COUNT,
            ]);
        }

        $setup = $this->pendingSetup($request, $user);

        return $this->render('@User/settings/two_factor.html.twig', [
            'enabled' => false,
            'required' => $this->availability->isRequiredFor($user),
            'qrCode' => $this->qrCodeDataUri($this->totpAuthenticator->getQRContent($setup)),
            'secret' => trim(chunk_split($setup->getSecret(), 4, ' ')),
        ]);
    }

    #[Route('/settings/two-factor/enable', name: 'app_settings_two_factor_enable', methods: ['POST'])]
    public function enable(Request $request): RedirectResponse
    {
        $user = $this->requireAvailableFeature();
        $this->requireCsrfToken($request);

        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_settings_two_factor');
        }

        $setup = $this->pendingSetup($request, $user);
        if (!$this->totpAuthenticator->checkCode($setup, $this->codeFrom($request))) {
            $this->addFlash('danger', new TranslatableMessage('flash.settings.two_factor.code_invalid', domain: 'user'));

            return $this->redirectToRoute('app_settings_two_factor');
        }

        $backupCodes = $this->backupCodeGenerator->generate($this->backupCodeHashCost);
        $user->enableTotpAuthentication(
            $this->cipher->encrypt($setup->getSecret()),
            $setup->getSecret(),
            $backupCodes['hashes'],
            $this->clock->now(),
        );
        $this->flush('user.settings.two_factor_enabled');

        $session = $request->getSession();
        $session->remove(self::PENDING_SECRET_SESSION_KEY);
        $session->set(self::NEW_BACKUP_CODES_SESSION_KEY, $backupCodes['codes']);
        $this->addFlash('success', new TranslatableMessage('flash.settings.two_factor.enabled', domain: 'user'));

        return $this->redirectToRoute('app_settings_two_factor_backup_codes');
    }

    /**
     * Shows newly created backup codes once.
     */
    #[Route('/settings/two-factor/backup-codes', name: 'app_settings_two_factor_backup_codes', methods: ['GET'])]
    public function backupCodes(Request $request): Response
    {
        $this->requireAvailableFeature();

        $codes = $request->getSession()->remove(self::NEW_BACKUP_CODES_SESSION_KEY);
        if (!\is_array($codes) || [] === $codes) {
            return $this->redirectToRoute('app_settings_two_factor');
        }

        $response = $this->render('@User/settings/two_factor_backup_codes.html.twig', ['codes' => $codes]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/settings/two-factor/backup-codes', name: 'app_settings_two_factor_renew_backup_codes', methods: ['POST'])]
    public function renewBackupCodes(Request $request): RedirectResponse
    {
        $user = $this->requireEnabledTwoFactor($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_settings_two_factor');
        }

        $backupCodes = $this->backupCodeGenerator->generate($this->backupCodeHashCost);
        $user->replaceBackupCodes($backupCodes['hashes']);
        $this->flush('user.settings.two_factor_backup_codes_renewed');

        $request->getSession()->set(self::NEW_BACKUP_CODES_SESSION_KEY, $backupCodes['codes']);

        return $this->redirectToRoute('app_settings_two_factor_backup_codes');
    }

    #[Route('/settings/two-factor/disable', name: 'app_settings_two_factor_disable', methods: ['POST'])]
    public function disable(Request $request): RedirectResponse
    {
        if ($this->availability->isRequiredFor($this->requireAvailableFeature())) {
            throw $this->createAccessDeniedException('Administrators must keep two-factor authentication.');
        }

        $user = $this->requireEnabledTwoFactor($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_settings_two_factor');
        }

        $user->disableTotpAuthentication();
        $this->flush('user.settings.two_factor_disabled');
        $this->addFlash('success', new TranslatableMessage('flash.settings.two_factor.disabled', domain: 'user'));

        return $this->redirectToRoute('app_settings_two_factor');
    }

    /**
     * For changes to an active setup: valid CSRF token, within the rate limit, and a current code from the app or a
     * backup code. Null (with a flash message) when the code is wrong or the limit is reached.
     */
    private function requireEnabledTwoFactor(Request $request): ?User
    {
        $user = $this->requireAvailableFeature();
        $this->requireCsrfToken($request);

        if (!$user->isTotpAuthenticationEnabled()) {
            return null;
        }

        if (!$this->codeLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            $this->addFlash('danger', new TranslatableMessage('flash.settings.two_factor.too_many_attempts', domain: 'user'));

            return null;
        }

        $code = $this->codeFrom($request);
        if (!$this->totpAuthenticator->checkCode($user, $code) && !$user->isBackupCode($code)) {
            $this->addFlash('danger', new TranslatableMessage('flash.settings.two_factor.code_invalid', domain: 'user'));

            return null;
        }

        return $user;
    }

    private function requireAvailableFeature(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->availability->isAvailable()) {
            throw $this->createNotFoundException('Two-factor authentication is not available.');
        }

        return $user;
    }

    private function requireCsrfToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('settings_two_factor', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('flash.security.invalid_csrf');
        }
    }

    private function codeFrom(Request $request): string
    {
        return trim($request->request->getString('code'));
    }

    private function pendingSetup(Request $request, User $user): PendingTotpSetup
    {
        $session = $request->getSession();
        $stored = $session->get(self::PENDING_SECRET_SESSION_KEY);
        $secret = \is_string($stored) ? $this->cipher->decrypt($stored) : null;
        if (null === $secret) {
            $secret = $this->totpAuthenticator->generateSecret();
            $session->set(self::PENDING_SECRET_SESSION_KEY, $this->cipher->encrypt($secret));
        }

        return new PendingTotpSetup($user->getUserIdentifier(), $secret);
    }

    private function qrCodeDataUri(string $content): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($content));
    }

    private function flush(string $intent): void
    {
        $this->auditContext->beginIntent($intent, []);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
