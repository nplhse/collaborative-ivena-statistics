<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testPublishedPublicPageIsResolvedByPath(): void
    {
        $client = self::createClient();

        $parent = PageFactory::createOne([
            'title' => 'Produkte',
            'slug' => 'produkte',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Produkte</p>']]],
        ])->_real();

        PageFactory::createOne([
            'title' => 'Hosting',
            'slug' => 'hosting',
            'parent' => $parent,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Hosting</p>']]],
        ]);

        $client->request(Request::METHOD_GET, '/produkte/hosting');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Hosting');
    }

    public function testDraftPageIsNotPubliclyVisible(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'Intern',
            'slug' => 'intern',
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $client->request(Request::METHOD_GET, '/intern');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAuthenticatedPageRequiresLogin(): void
    {
        $client = self::createClient();

        PageFactory::createOne([
            'title' => 'Mitgliederbereich',
            'slug' => 'mitgliederbereich',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $client->request(Request::METHOD_GET, '/mitgliederbereich');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/login');

        $user = UserFactory::createOne()->_real();
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/mitgliederbereich');
        self::assertResponseIsSuccessful();
    }
}
