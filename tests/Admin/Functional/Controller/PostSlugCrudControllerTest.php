<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PostSlugCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testCreatePostWithEmptySlugGeneratesSlugFromTitle(): void
    {
        $client = $this->createAdminClient();
        $category = PostCategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);

        $crawler = $client->request(Request::METHOD_GET, '/admin/post/new');
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['Post[title]'] = 'My Generated Slug Post';
        $form['Post[slug]'] = '';
        $form['Post[category]'] = (string) $category->getId();
        $form['Post[status]'] = '0';
        $form['Post[content]'] = '<p>Content</p>';

        $client->submit($form);
        self::assertResponseRedirects();

        $post = PostFactory::repository()->findOneBy(['title' => 'My Generated Slug Post']);
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('my-generated-slug-post', $post->getSlug());
    }

    public function testCreatePostWithManualSlugPersistsSlugAsEntered(): void
    {
        $client = $this->createAdminClient();
        $category = PostCategoryFactory::createOne(['name' => 'Updates', 'slug' => 'updates']);

        $crawler = $client->request(Request::METHOD_GET, '/admin/post/new');
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['Post[title]'] = 'Different Title';
        $form['Post[slug]'] = 'custom-manual-slug';
        $form['Post[category]'] = (string) $category->getId();
        $form['Post[status]'] = '0';
        $form['Post[content]'] = '<p>Content</p>';

        $client->submit($form);
        self::assertResponseRedirects();

        $post = PostFactory::repository()->findOneBy(['slug' => 'custom-manual-slug']);
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('Different Title', $post->getTitle());
    }

    public function testUpdatePostPreservesManualSlugWhenTitleChanges(): void
    {
        $client = $this->createAdminClient();
        $post = PostFactory::createOne([
            'title' => 'Original Title',
            'slug' => 'keep-this-slug',
            'status' => PostStatus::DRAFT,
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/admin/post/%d/edit', $post->getId()),
        );
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler, 'Save changes')->form();
        $form['Post[title]'] = 'Changed Title';
        $form['Post[slug]'] = 'keep-this-slug';

        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $updated = PostFactory::repository()->find($post->getId());
        self::assertInstanceOf(Post::class, $updated);
        self::assertSame('keep-this-slug', $updated->getSlug());
        self::assertSame('Changed Title', $updated->getTitle());
    }

    public function testCreatePostWithInvalidSlugShowsValidationError(): void
    {
        $client = $this->createAdminClient();
        $category = PostCategoryFactory::createOne(['name' => 'Invalid', 'slug' => 'invalid-cat']);

        $crawler = $client->request(Request::METHOD_GET, '/admin/post/new');
        self::assertResponseIsSuccessful();

        $form = $this->selectSaveForm($crawler)->form();
        $form['Post[title]'] = 'Invalid Slug Post';
        $form['Post[slug]'] = 'Invalid Slug!';
        $form['Post[category]'] = (string) $category->getId();
        $form['Post[status]'] = '0';
        $form['Post[content]'] = '<p>Content</p>';

        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertNull(PostFactory::repository()->findOneBy(['title' => 'Invalid Slug Post']));
    }

    private function createAdminClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'post-slug-admin-'.bin2hex(random_bytes(4)),
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
