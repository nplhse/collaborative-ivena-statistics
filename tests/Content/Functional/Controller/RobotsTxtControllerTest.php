<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RobotsTxtControllerTest extends WebTestCase
{
    public function testRobotsTxtContainsSitemapAndDisallows(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/plain; charset=UTF-8');

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('User-agent: *', $content);
        self::assertStringContainsString('Allow: /', $content);
        self::assertStringContainsString('Disallow: /admin', $content);
        self::assertStringContainsString('Disallow: /statistics', $content);
        self::assertStringContainsString('Disallow: /explore', $content);
        self::assertStringContainsString('Disallow: /hospitals', $content);
        self::assertStringContainsString('Disallow: /settings', $content);
        self::assertStringContainsString('Disallow: /import', $content);
        self::assertStringContainsString('Disallow: /health', $content);
        self::assertStringContainsString('Disallow: /login/confirm', $content);
        self::assertStringContainsString('Disallow: /_error', $content);
        self::assertStringContainsString('Disallow: /_error_preview', $content);
        self::assertStringContainsString('Disallow: /_ui/', $content);
        self::assertStringContainsString('Disallow: /feedback', $content);
        self::assertMatchesRegularExpression('#Sitemap:\s+https?://[^\s]+/sitemap\.xml#', $content);
    }
}
