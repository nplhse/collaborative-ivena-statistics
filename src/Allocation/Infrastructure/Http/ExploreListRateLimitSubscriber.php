<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Http;

use App\Shared\Application\RateLimit\ClientRateLimit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Shared rate limit for Explore GET scraping protection (lists, details, worklists).
 *
 * Runs after the security firewall (priority 8) so the user identity is available
 * for the limiter key instead of collapsing every request into "anon".
 *
 * @psalm-suppress UnusedClass
 */
final readonly class ExploreListRateLimitSubscriber
{
    private const string EXPLORE_PATH_PREFIX = '/explore';

    public function __construct(
        #[Autowire(service: 'limiter.explore_list')]
        private RateLimiterFactory $exploreListLimiter,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (Request::METHOD_GET !== $request->getMethod() || !$this->isLimitedExplorePath($request->getPathInfo())) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        $userKey = $user instanceof UserInterface ? $user->getUserIdentifier() : 'anon';
        // User and IP are hashed like in every other limiter key (#1675): the cache keeps no raw IP address.
        if (ClientRateLimit::acceptUserAndIp($this->exploreListLimiter, 'explore_list', $userKey, $request)) {
            return;
        }

        $event->setResponse(new Response('Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS));
    }

    private function isLimitedExplorePath(string $path): bool
    {
        return self::EXPLORE_PATH_PREFIX === $path || str_starts_with($path, self::EXPLORE_PATH_PREFIX.'/');
    }
}
