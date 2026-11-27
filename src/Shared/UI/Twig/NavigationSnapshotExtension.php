<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * The public navigation of the header and the footer for the error pages (#1684).
 *
 * The navigation comes from the database (CMS pages), which may be what failed. Normal pages therefore keep a copy of
 * the rendered links in `cache.app` (files, not the database), per menu and language, built as for a visitor who is
 * not signed in and renewed at most once an hour. Error pages only read that copy: with it they show the usual links,
 * without it none. The copy has no expiry, so it is still there during an outage; `cache:clear` removes it.
 *
 * @psalm-suppress UnusedClass Registered through #[AsTwigFunction].
 */
final readonly class NavigationSnapshotExtension
{
    private const int MAX_AGE_SECONDS = 3600;

    /** The menus kept for the error pages, with the template that renders `this.menu`. */
    private const array TEMPLATES = [
        'primary' => '@Shared/components/Navigation/Primary.html.twig',
        'footer' => '@Shared/components/Navigation/Footer.html.twig',
    ];

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private NavigationBuilder $navigationBuilder,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Renews the copies for the current language when they are missing or older than an hour. Called by the footer of
     * normal pages, never by the error pages.
     *
     * @psalm-suppress PossiblyUnusedMethod Called from Twig.
     */
    #[AsTwigFunction('navigation_snapshot_refresh', needsEnvironment: true)]
    public function refresh(Environment $twig, string $locale): string
    {
        $now = $this->clock->now()->getTimestamp();
        foreach (self::TEMPLATES as $name => $template) {
            try {
                $item = $this->cache->getItem($this->key($name, $locale));
                $stored = $item->get();
                if ($item->isHit() && \is_array($stored) && \is_int($stored['at'] ?? null) && $now - $stored['at'] < self::MAX_AGE_SECONDS) {
                    continue;
                }

                // As for a visitor who is not signed in: no entry that needs a role or a grant, none marked active.
                $menu = $this->navigationBuilder->build(Menu::from($name), isGranted: static fn (): bool => false);
                $item->set(['at' => $now, 'html' => $twig->render($template, ['this' => ['menu' => $menu]])]);
                $this->cache->save($item);
            } catch (\Throwable $exception) {
                // The copy is a convenience for error pages; a normal page never fails because of it.
                $this->logger->warning('Navigation snapshot for the error page could not be renewed.', ['exception' => $exception]);
            }
        }

        return '';
    }

    /**
     * The stored links of a menu, or null. Never throws, as it runs while an error page is rendered.
     *
     * @psalm-suppress PossiblyUnusedMethod Called from Twig.
     */
    #[AsTwigFunction('navigation_snapshot', isSafe: ['html'])]
    public function snapshot(string $name, string $locale): ?string
    {
        if (!\array_key_exists($name, self::TEMPLATES)) {
            return null;
        }

        try {
            $stored = $this->cache->getItem($this->key($name, $locale))->get();
        } catch (\Throwable $exception) {
            $this->logger->warning('Navigation snapshot for the error page could not be read.', ['exception' => $exception]);

            return null;
        }

        return \is_array($stored) && \is_string($stored['html'] ?? null) ? $stored['html'] : null;
    }

    private function key(string $name, string $locale): string
    {
        return 'navigation_snapshot.'.$name.'.'.(1 === preg_match('/^[a-z]{2}$/', $locale) ? $locale : 'default');
    }
}
