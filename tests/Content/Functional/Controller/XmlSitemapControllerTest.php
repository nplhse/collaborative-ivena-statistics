<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class XmlSitemapControllerTest extends WebTestCase
{
    use Factories;

    public function testGuestCanViewXmlSitemapWithPublicContentOnly(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'slug' => 'xml-public-page',
            'path' => '/xml-public-page',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'xml-auth-page',
            'path' => '/xml-auth-page',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        PostFactory::createOne([
            'slug' => 'xml-visible-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        PostFactory::createOne([
            'slug' => 'xml-future-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('+2 days'),
        ]);

        $client->request(Request::METHOD_GET, '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/xml; charset=UTF-8');

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<urlset', $content);
        self::assertStringContainsString('/blog', $content);
        self::assertStringContainsString('/xml-public-page', $content);
        self::assertStringContainsString('/blog/xml-visible-post', $content);
        self::assertStringNotContainsString('/xml-auth-page', $content);
        self::assertStringNotContainsString('/blog/xml-future-post', $content);
        self::assertStringNotContainsString('/login', $content);
        self::assertStringNotContainsString('/statistics', $content);
    }
}
