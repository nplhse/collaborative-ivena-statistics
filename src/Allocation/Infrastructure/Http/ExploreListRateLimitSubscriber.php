<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Http;

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

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
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
        $limiterKey = sprintf('explore_list_%s_%s', sha1($userKey), $request->getClientIp() ?? 'unknown');

        $limit = $this->exploreListLimiter->create($limiterKey)->consume(1);
        if ($limit->isAccepted()) {
            return;
        }

        $event->setResponse(new Response('Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS));
    }

    private function isLimitedExplorePath(string $path): bool
    {
        return self::EXPLORE_PATH_PREFIX === $path || str_starts_with($path, self::EXPLORE_PATH_PREFIX.'/');
    }
}
