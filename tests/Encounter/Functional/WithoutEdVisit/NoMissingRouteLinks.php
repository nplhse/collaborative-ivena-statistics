<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\WithoutEdVisit;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every internal link on the given pages resolves to a route of the booted kernel, including the EasyAdmin
 * links that name a route in `routeName` (#1300 step 5).
 */
trait NoMissingRouteLinks
{
    /**
     * @param list<string> $pages
     */
    private function assertNoLinkToAMissingRoute(KernelBrowser $client, array $pages): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $client->followRedirects();

        $missing = [];
        foreach ($pages as $page) {
            $crawler = $client->request('GET', $page);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $page);
            foreach ($crawler->filter('a[href^="/"]')->each(static fn ($link): string => (string) $link->attr('href')) as $href) {
                $path = (string) parse_url($href, \PHP_URL_PATH);
                parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
                if (\is_string($query['routeName'] ?? null) && null === $router->getRouteCollection()->get($query['routeName'])) {
                    $missing[] = $page.' → '.$href;
                }

                try {
                    $router->match($path);
                } catch (MethodNotAllowedException) {
                } catch (ResourceNotFoundException) {
                    $missing[] = $page.' → '.$href;
                }
            }
        }

        $client->followRedirects(false);
        self::assertSame([], array_values(array_unique($missing)));
    }
}
