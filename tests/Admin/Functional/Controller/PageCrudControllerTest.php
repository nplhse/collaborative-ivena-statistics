<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanOpenPageIndexAndNewForm(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/page');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Pages');

        $client->request(Request::METHOD_GET, '/admin/page/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Create Page');
    }

    public function testNonAdminUserGetsForbiddenOnPageIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'page-regular-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/page');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPageContentBlockOrderIsPersistedWhenSubmittedInReverseOrder(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-reorder-'.bin2hex(random_bytes(4)),
            ])
        ;

        $page = PageFactory::createOne([
            'title' => 'Reorder Test',
            'slug' => 'reorder-test',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'First block', 'level' => 'h2'],
                ],
                [
                    'type' => 'headline',
                    'enabled' => true,
                    'data' => ['text' => 'Second block', 'level' => 'h2'],
                ],
            ],
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/page/%d/edit', $page->getId()),
        );

        self::assertResponseIsSuccessful();

        $saveButton = $crawler->selectButton('Save changes');
        if (0 === $saveButton->count()) {
            $saveButton = $crawler->selectButton('Save');
        }

        $form = $saveButton->form();
        $form['Page[content][0][type]'] = 'headline';
        $form['Page[content][0][enabled]'] = '1';
        $form['Page[content][0][data][text]'] = 'Second block';
        $form['Page[content][0][data][level]'] = 'h2';
        $form['Page[content][1][type]'] = 'headline';
        $form['Page[content][1][enabled]'] = '1';
        $form['Page[content][1][data][text]'] = 'First block';
        $form['Page[content][1][data][level]'] = 'h2';

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $updated = PageFactory::repository()->findOneBy(['slug' => 'reorder-test']);
        self::assertInstanceOf(Page::class, $updated);

        $content = $updated->getContent();
        self::assertSame('Second block', $content[0]['data']['text'] ?? null);
        self::assertSame('First block', $content[1]['data']['text'] ?? null);
    }
}
