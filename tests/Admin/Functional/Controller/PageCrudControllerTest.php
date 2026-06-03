<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageCrudControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testAdminCanOpenPageIndexAndNewForm(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/page')
            ->assertSuccessful()
            ->assertSee('Pages')
            ->visit('/admin/page/new')
            ->assertSuccessful()
            ->assertSee('Create Page')
        ;
    }

    public function testNonAdminUserGetsForbiddenOnPageIndex(): void
    {
        $user = UserFactory::createOne([
            'username' => 'page-regular-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/admin/page')
            ->assertStatus(403)
        ;
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
        ])->_real();

        $client->loginUser($admin->_real());
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

        $updated = PageFactory::repository()->findOneBy(['slug' => 'reorder-test'])?->_real();
        self::assertInstanceOf(Page::class, $updated);

        $content = $updated->getContent();
        self::assertSame('Second block', $content[0]['data']['text'] ?? null);
        self::assertSame('First block', $content[1]['data']['text'] ?? null);
    }
}
