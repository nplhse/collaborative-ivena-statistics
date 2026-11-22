<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Notification\PostAnnouncementNotification;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PostCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanOpenBlogPostIndex(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'blog-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/post');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Blog posts');
    }

    public function testPostIndexOmitsTimestampColumns(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'blog-index-'.bin2hex(random_bytes(4)),
            ])
        ;
        PostFactory::createOne([
            'title' => 'Index Post '.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/post');
        self::assertResponseIsSuccessful();

        $headerText = implode(' | ', $crawler->filter('table thead th')->each(static fn ($node): string => trim($node->text())));
        self::assertStringContainsString('Title', $headerText);
        self::assertStringNotContainsString('Created', $headerText);
        self::assertStringNotContainsString('Updated', $headerText);
    }

    public function testNonAdminUserGetsForbiddenOnBlogPostIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'blog-regular-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/post');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSendsAPublishedPostAsAnnouncementOnce(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $post = PostFactory::createOne([
            'title' => 'Version 0.3 is here',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('2020-01-01 09:00:00'),
        ]);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/post/'.$post->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action*="/announce"]')->form();
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', '“Version 0.3 is here” is being sent as an announcement.');
        self::assertSelectorNotExists('form[action*="/announce"]');
        self::assertGreaterThan(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM notification WHERE type = ?', [PostAnnouncementNotification::TYPE]));
    }

    public function testDraftOffersNoAnnouncement(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $post = PostFactory::createOne(['status' => PostStatus::DRAFT]);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/post/'.$post->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action*="/announce"]');
    }
}
