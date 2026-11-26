<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\Shared\Infrastructure\Mail\TransactionalMailer;
use App\User\Domain\Entity\User;
use App\User\Domain\Validator\UserUsernameConstraints;
use App\User\Infrastructure\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Signature\Exception\ExpiredSignatureException;
use Symfony\Component\Security\Core\Signature\Exception\InvalidSignatureException;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Sign-in with a one-time link sent by email (#1355). Requesting a link always ends on the same page, whether or not
 * a mail was sent, so the form reveals nothing about accounts. The link itself opens a confirmation page; the firewall
 * (`login_link` in `security.yaml`) signs in only on its POST.
 *
 * @psalm-suppress UnusedClass
 */
final class LoginLinkController extends AbstractController
{
    /** Must match `login_link.lifetime` in `config/packages/security.yaml`. */
    public const int LIFETIME_MINUTES = 15;

    /** Must match `login_link.signature_properties` in `config/packages/security.yaml`. */
    private const array SIGNATURE_PROPERTIES = ['email', 'password', 'loginLinkAllowed'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LoginLinkHandlerInterface $loginLinkHandler,
        private readonly TransactionalMailer $transactionalMailer,
        private readonly LocaleResolver $localeResolver,
        private readonly CookieConsentService $cookieConsentService,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $logger,
        private readonly PropertyAccessorInterface $propertyAccessor,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
    }

    /**
     * Takes the request from its own form (`identifier`) and from the second button of the login form, which posts
     * `login[username]`.
     */
    #[Route('/login/link', name: 'app_login_link_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        #[Autowire(service: 'limiter.login_link_request')]
        RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.login_link_request_identifier')]
        RateLimiterFactory $identifierLimiter,
    ): Response {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_default');
        }

        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->render('@User/security/login_link_request.html.twig', [
                'identifier' => $request->query->getString('identifier'),
            ]);
        }

        if (!$this->isCsrfTokenValid('login_link_request', $request->request->getString('_link_csrf_token'))) {
            throw $this->createAccessDeniedException('flash.security.invalid_csrf');
        }

        $identifier = $this->identifierFrom($request);
        if ('' === $identifier) {
            return $this->redirectToRoute('app_login_link_request');
        }

        $ipAccepted = $ipLimiter->create(sha1($request->getClientIp() ?? 'unknown'))->consume()->isAccepted();
        $identifierAccepted = $identifierLimiter->create(sha1(mb_strtolower($identifier)))->consume()->isAccepted();
        if (!$ipAccepted || !$identifierAccepted) {
            $this->logger->warning('security.login_link.rate_limited', [
                'identifier_hash' => hash('sha256', mb_strtolower($identifier)),
                'ip' => $request->getClientIp(),
            ]);

            return $this->redirectToRoute('app_login_link_sent');
        }

        $user = $this->findUser($identifier);
        if ($user instanceof User && $user->isEnabled() && $user->isVerified() && $user->isLoginLinkAllowed()) {
            $loginLink = $this->loginLinkHandler->createLoginLink($user, $request);
            $this->transactionalMailer->sendLoginLinkEmail(
                (string) $user->getEmail(),
                $loginLink->getUrl(),
                self::LIFETIME_MINUTES,
                $this->localeResolver->resolveForUser($user),
            );
        }

        return $this->redirectToRoute('app_login_link_sent');
    }

    #[Route('/login/link/sent', name: 'app_login_link_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('@User/security/login_link_sent.html.twig', [
            'lifetimeMinutes' => self::LIFETIME_MINUTES,
        ]);
    }

    /**
     * GET shows the confirmation page; the POST of that page is handled by the firewall (`check_post_only`).
     */
    #[Route('/login/link/check', name: 'app_login_link_check', methods: ['GET', 'POST'])]
    public function check(Request $request): Response
    {
        $user = $request->query->getString('user');
        $expires = $request->query->getString('expires');
        $hash = $request->query->getString('hash');
        if (in_array('', [$user, $expires, $hash], true)) {
            return $this->redirectToRoute('app_login');
        }

        $consent = $this->cookieConsentService->resolveForRequest($request, null);

        return $this->render('@User/security/login_link_confirm.html.twig', [
            'username' => $user,
            'expires' => $expires,
            'hash' => $hash,
            'account' => $this->accountOfValidLink($user, $expires, $hash),
            'hasConsentDecision' => $consent->getDecidedAt() instanceof \DateTimeImmutable,
        ]);
    }

    /**
     * The account behind a valid link, checked without using the link up, so the confirmation page can show its
     * avatar and display name: whoever holds a valid link can sign in anyway. Null for invalid or expired links.
     */
    private function accountOfValidLink(string $username, string $expires, string $hash): ?User
    {
        $user = $this->userRepository->findOneBy(['username' => $username]);
        if (!$user instanceof User || !ctype_digit($expires)) {
            return null;
        }

        try {
            new SignatureHasher($this->propertyAccessor, self::SIGNATURE_PROPERTIES, $this->secret)
                ->verifySignatureHash($user, (int) $expires, $hash);
        } catch (InvalidSignatureException|ExpiredSignatureException) {
            return null;
        }

        return $user;
    }

    private function identifierFrom(Request $request): string
    {
        $identifier = $request->request->getString('identifier');
        if ('' === $identifier) {
            $login = $request->request->all('login');
            $identifier = \is_string($login['username'] ?? null) ? $login['username'] : '';
        }

        return UserUsernameConstraints::trim($identifier);
    }

    /**
     * Same rule as the password login (`LoginFormAuthenticator`): an identifier with "@" is an email.
     */
    private function findUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return $this->userRepository->findOneBy(['email' => mb_strtolower($identifier)]);
        }

        return $this->userRepository->findOneBy(['username' => $identifier]);
    }
}
