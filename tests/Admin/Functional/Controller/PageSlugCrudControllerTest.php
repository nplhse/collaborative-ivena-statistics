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
final class PageSlugCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testCreatePageWithEmptySlugGeneratesSlugFromTitle(): void
    {
        $client = $this->createAdminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin/page/new');
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['Page[title]'] = 'Generated Page Title';
        $form['Page[slug]'] = '';
        $form['Page[status]'] = Page::STATUS_DRAFT;
        $form['Page[visibility]'] = Page::VISIBILITY_PUBLIC;

        $client->submit($form);
        self::assertResponseRedirects();

        $page = PageFactory::repository()->findOneBy(['title' => 'Generated Page Title']);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame('generated-page-title', $page->getSlug());
        self::assertSame('/generated-page-title', $page->getPath());
    }

    public function testCreatePageWithManualSlugPersistsSlugAsEntered(): void
    {
        $client = $this->createAdminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin/page/new');
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['Page[title]'] = 'Different Page Title';
        $form['Page[slug]'] = 'custom-page-slug';
        $form['Page[status]'] = Page::STATUS_DRAFT;
        $form['Page[visibility]'] = Page::VISIBILITY_PUBLIC;

        $client->submit($form);
        self::assertResponseRedirects();

        $page = PageFactory::repository()->findOneBy(['slug' => 'custom-page-slug']);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame('/custom-page-slug', $page->getPath());
    }

    public function testUpdatePagePreservesManualSlugWhenTitleChanges(): void
    {
        $client = $this->createAdminClient();
        $page = PageFactory::createOne([
            'title' => 'Original Page',
            'slug' => 'keep-page-slug',
            'status' => Page::STATUS_DRAFT,
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/page/%d/edit', $page->getId()),
        );
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler, 'Save changes')->form();
        $form['Page[title]'] = 'Renamed Page';
        $form['Page[slug]'] = 'keep-page-slug';

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $updated = PageFactory::repository()->find($page->getId());
        self::assertInstanceOf(Page::class, $updated);
        self::assertSame('keep-page-slug', $updated->getSlug());
        self::assertSame('/keep-page-slug', $updated->getPath());
        self::assertSame('Renamed Page', $updated->getTitle());
    }

    public function testCreatePageWithInvalidSlugShowsValidationError(): void
    {
        $client = $this->createAdminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin/page/new');
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['Page[title]'] = 'Invalid Slug Page';
        $form['Page[slug]'] = 'Invalid Slug!';
        $form['Page[status]'] = Page::STATUS_DRAFT;
        $form['Page[visibility]'] = Page::VISIBILITY_PUBLIC;

        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertNull(PageFactory::repository()->findOneBy(['title' => 'Invalid Slug Page']));
    }

    private function createAdminClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-slug-admin-'.bin2hex(random_bytes(4)),
            ])
        ;
        $client->loginUser($admin);

        return $client;
    }

    private function selectSaveForm(\Symfony\Component\DomCrawler\Crawler $crawler, string $preferredLabel = 'Save'): \Symfony\Component\DomCrawler\Crawler
    {
        $button = $crawler->selectButton($preferredLabel);
        if (0 === $button->count() && 'Save' === $preferredLabel) {
            $button = $crawler->selectButton('Create');
        }
        if (0 === $button->count() && 'Save changes' !== $preferredLabel) {
            $button = $crawler->selectButton('Save changes');
        }

        self::assertGreaterThan(0, $button->count(), 'Save button not found on form.');

        return $button;
    }
}
