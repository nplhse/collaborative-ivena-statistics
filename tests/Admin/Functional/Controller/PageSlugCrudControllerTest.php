<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
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

    public function testCreateTranslationWithEmptySlugGeneratesSlugFromTitle(): void
    {
        $client = $this->createAdminClient();
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'structural-page-a',
            'path' => '/structural-page-a',
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/page-translation/new?pageId=%d&locale=en', $page->getId()),
        );
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['PageTranslation[page]'] = (string) $page->getId();
        $form['PageTranslation[locale]'] = 'en';
        $form['PageTranslation[title]'] = 'Generated Page Title';
        $form['PageTranslation[slug]'] = '';
        $form['PageTranslation[status]'] = PageTranslation::STATUS_DRAFT;

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(Page::class, $page->getId());
        self::assertInstanceOf(Page::class, $reloaded);
        $translation = $reloaded->translation('en');
        self::assertInstanceOf(PageTranslation::class, $translation);
        self::assertSame('generated-page-title', $translation->getSlug());
        self::assertSame('/generated-page-title', $translation->getPath());
    }

    public function testCreateTranslationWithManualSlugPersistsSlugAsEntered(): void
    {
        $client = $this->createAdminClient();
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'structural-page-b',
            'path' => '/structural-page-b',
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/page-translation/new?pageId=%d&locale=en', $page->getId()),
        );
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['PageTranslation[page]'] = (string) $page->getId();
        $form['PageTranslation[locale]'] = 'en';
        $form['PageTranslation[title]'] = 'Different Page Title';
        $form['PageTranslation[slug]'] = 'custom-page-slug';
        $form['PageTranslation[status]'] = PageTranslation::STATUS_DRAFT;

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(Page::class, $page->getId());
        self::assertInstanceOf(Page::class, $reloaded);
        $translation = $reloaded->translation('en');
        self::assertInstanceOf(PageTranslation::class, $translation);
        self::assertSame('custom-page-slug', $translation->getSlug());
        self::assertSame('/custom-page-slug', $translation->getPath());
    }

    public function testUpdateTranslationPreservesManualSlugWhenTitleChanges(): void
    {
        $client = $this->createAdminClient();
        $page = PageFactory::createOne([
            'title' => 'Original Page',
            'slug' => 'keep-page-slug',
            'status' => PageTranslation::STATUS_DRAFT,
        ]);
        $translation = $page->translation('en');
        self::assertInstanceOf(PageTranslation::class, $translation);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/page-translation/%d/edit', $translation->getId()),
        );
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler, 'Save changes')->form();
        $form['PageTranslation[title]'] = 'Renamed Page';
        $form['PageTranslation[slug]'] = 'keep-page-slug';

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $updatedPage = PageFactory::repository()->find($page->getId());
        self::assertInstanceOf(Page::class, $updatedPage);
        $updated = $updatedPage->translation('en');
        self::assertInstanceOf(PageTranslation::class, $updated);
        self::assertSame('keep-page-slug', $updated->getSlug());
        self::assertSame('/keep-page-slug', $updated->getPath());
        self::assertSame('Renamed Page', $updated->getTitle());
    }

    public function testCreateTranslationWithInvalidSlugShowsValidationError(): void
    {
        $client = $this->createAdminClient();
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'structural-page-c',
            'path' => '/structural-page-c',
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/page-translation/new?pageId=%d&locale=en', $page->getId()),
        );
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['PageTranslation[page]'] = (string) $page->getId();
        $form['PageTranslation[locale]'] = 'en';
        $form['PageTranslation[title]'] = 'Invalid Slug Page';
        $form['PageTranslation[slug]'] = 'Invalid Slug!';
        $form['PageTranslation[status]'] = PageTranslation::STATUS_DRAFT;

        $client->submit($form);

        self::assertResponseIsUnprocessable();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(Page::class, $page->getId());
        self::assertInstanceOf(Page::class, $reloaded);
        self::assertNull($reloaded->translation('en'));
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
