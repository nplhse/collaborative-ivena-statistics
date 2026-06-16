<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Application\Media\MediaImageFigureHtmlBuilder;
use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Factory\PostTagFactory;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BlogControllerTest extends WebTestCase
{
    use Factories;

    public function testIndexOnlyShowsPublishedPostsThatAreDue(): void
    {
        $client = self::createClient();
        $author = UserFactory::createOne(['username' => 'blog-author']);

        $category = PostCategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $tag = PostTagFactory::createOne(['name' => 'Release', 'slug' => 'release']);

        PostFactory::createOne([
            'title' => 'Visible Post',
            'slug' => 'visible-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'category' => $category,
            'tags' => [$tag],
            'createdBy' => $author,
        ]);

        PostFactory::createOne([
            'title' => 'Future Post',
            'slug' => 'future-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('+1 day'),
            'category' => $category,
        ]);

        PostFactory::createOne([
            'title' => 'Draft Post',
            'slug' => 'draft-post',
            'status' => PostStatus::DRAFT,
            'publishedAt' => new \DateTimeImmutable('-1 day'),
            'category' => $category,
        ]);

        $client->request(Request::METHOD_GET, '/blog');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-title', 'All posts');
        self::assertSelectorTextContains('body', 'Latest posts');
        self::assertSelectorTextContains('body', 'Categories');
        self::assertSelectorTextContains('body', 'Tags');
        self::assertSelectorTextContains('body', 'RSS feed');
        self::assertSelectorTextContains('body', 'Visible Post');
        self::assertSelectorTextContains('.col-lg-8', 'blog-author');
        self::assertSelectorTextNotContains('body', 'Future Post');
        self::assertSelectorTextNotContains('body', 'Draft Post');
    }

    public function testIndexSupportsPagination(): void
    {
        $client = self::createClient();
        $category = PostCategoryFactory::createOne(['name' => 'Pagination', 'slug' => 'pagination']);

        for ($i = 1; $i <= 12; ++$i) {
            PostFactory::createOne([
                'title' => sprintf('Paginated Post %d', $i),
                'slug' => sprintf('paginated-post-%d', $i),
                'status' => PostStatus::PUBLISHED,
                'publishedAt' => new \DateTimeImmutable(sprintf('-%d minutes', $i)),
                'category' => $category,
            ]);
        }

        $client->request(Request::METHOD_GET, '/blog?limit=5&page=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Paginated Post 1');
        self::assertSelectorTextNotContains('body', 'Paginated Post 8');

        $client->request(Request::METHOD_GET, '/blog?limit=5&page=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Paginated Post 6');
        self::assertSelectorTextContains('.text-body-secondary', '6-10 of 12 posts');

        $client->request(Request::METHOD_GET, '/blog');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.text-body-secondary', '1-10 of 12 posts');
        self::assertSelectorExists('.pagination');
    }

    public function testCategoryAndTagFiltersUsePublishedDuePosts(): void
    {
        $client = self::createClient();

        $news = PostCategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $dev = PostCategoryFactory::createOne(['name' => 'Dev', 'slug' => 'dev']);
        $php = PostTagFactory::createOne(['name' => 'PHP', 'slug' => 'php']);
        $symfony = PostTagFactory::createOne(['name' => 'Symfony', 'slug' => 'symfony']);

        PostFactory::createOne([
            'title' => 'News PHP',
            'slug' => 'news-php',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-2 hours'),
            'category' => $news,
            'tags' => [$php],
        ]);

        PostFactory::createOne([
            'title' => 'Dev Symfony',
            'slug' => 'dev-symfony',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hours'),
            'category' => $dev,
            'tags' => [$symfony],
        ]);

        $client->request(Request::METHOD_GET, '/blog/category/news');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-title', 'Category: News');
        self::assertSelectorTextContains('.col-lg-8', 'News PHP');
        self::assertSelectorTextNotContains('.col-lg-8', 'Dev Symfony');

        $client->request(Request::METHOD_GET, '/blog/tag/symfony');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-title', 'Tag: Symfony');
        self::assertSelectorTextContains('.col-lg-8', 'Dev Symfony');
        self::assertSelectorTextNotContains('.col-lg-8', 'News PHP');
    }

    public function testRssContainsOnlyDuePublishedPosts(): void
    {
        $client = self::createClient();
        $category = PostCategoryFactory::createOne();

        PostFactory::createOne([
            'title' => 'RSS visible',
            'slug' => 'rss-visible',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'category' => $category,
        ]);

        PostFactory::createOne([
            'title' => 'RSS future',
            'slug' => 'rss-future',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('+3 hours'),
            'category' => $category,
        ]);

        $client->request(Request::METHOD_GET, '/blog/rss.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/rss+xml; charset=UTF-8');

        $xml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('RSS visible', $xml);
        self::assertStringNotContainsString('RSS future', $xml);
    }

    public function testAnonymousUserCannotPostComment(): void
    {
        $client = self::createClient();
        PostFactory::createOne([
            'title' => 'Comment target',
            'slug' => 'comment-target',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        $client->request(Request::METHOD_POST, '/blog/comment-target/comments', [
            'content' => 'Test',
            '_token' => 'invalid',
        ]);

        self::assertResponseRedirects('/login', Response::HTTP_FOUND);
    }

    public function testAuthenticatedUserCanPostComment(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'blog-comment-user'])->_real();
        $post = PostFactory::createOne([
            'title' => 'Comment target',
            'slug' => 'comment-target-auth',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
        ])->_real();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/blog/comment-target-auth');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $client->request(Request::METHOD_POST, '/blog/comment-target-auth/comments', [
            'content' => 'Mein Kommentar',
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/blog/comment-target-auth', Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Mein Kommentar');
        self::assertSelectorTextContains('body', $user->getUserIdentifier());
        self::assertSelectorTextContains('body', (string) $post->getTitle());
    }

    public function testShowRendersEntityEncodedImageSnippet(): void
    {
        $client = self::createClient();
        $category = PostCategoryFactory::createOne(['name' => 'Media', 'slug' => 'media']);

        PostFactory::createOne([
            'title' => 'Post With Image',
            'slug' => 'post-with-image',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'category' => $category,
            'content' => '<p>Intro</p>&lt;img src=&quot;/uploads/media/sample.png&quot; alt=&quot;Sample&quot;&gt;',
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/blog/post-with-image');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('img[src="/uploads/media/sample.png"]'));
    }

    public function testShowRendersFloatedFigureWithLayoutClasses(): void
    {
        $client = self::createClient();
        $category = PostCategoryFactory::createOne(['name' => 'Layout', 'slug' => 'layout']);
        $figure = new MediaImageFigureHtmlBuilder()->build('/uploads/media/sample.png', 'Sample', 'md', 'left');

        PostFactory::createOne([
            'title' => 'Post With Floated Image',
            'slug' => 'post-with-floated-image',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'category' => $category,
            'content' => '<p>Intro</p>'.$figure.'<p>More text</p>',
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/blog/post-with-floated-image');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.content.page-content-blocks');
        self::assertSelectorExists('figure.page-content-image--size-md.page-content-image--float-left');
        self::assertCount(1, $crawler->filter('img[src="/uploads/media/sample.png"]'));
    }

    public function testShowRendersPrevNextNavigation(): void
    {
        $client = self::createClient();
        $category = PostCategoryFactory::createOne(['name' => 'Nav', 'slug' => 'nav']);
        $author = UserFactory::createOne(['username' => 'detail-author']);

        PostFactory::createOne([
            'title' => 'Older Post',
            'slug' => 'older-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-3 hours'),
            'category' => $category,
        ]);

        PostFactory::createOne([
            'title' => 'Current Post',
            'slug' => 'current-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-2 hours'),
            'category' => $category,
            'createdBy' => $author,
        ]);

        PostFactory::createOne([
            'title' => 'Newer Post',
            'slug' => 'newer-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'category' => $category,
        ]);

        $client->request(Request::METHOD_GET, '/blog/current-post');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.list-inline', 'Author: detail-author');
        self::assertSelectorTextContains('.page-prev', 'Older Post');
        self::assertSelectorTextContains('.page-next', 'Newer Post');
    }

    public function testCreatingPostWritesAuditLogEntry(): void
    {
        $author = UserFactory::createOne(['username' => 'audit-post-author'])->_real();
        $category = PostCategoryFactory::createOne(['name' => 'Audit Category', 'slug' => 'audit-category']);

        $post = PostFactory::createOne([
            'title' => 'Audited Post',
            'slug' => 'audited-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'category' => $category,
            'createdBy' => $author,
        ])->_real();

        /** @var \Doctrine\ORM\EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        /** @var \Doctrine\ORM\EntityRepository<AuditEntry> $auditRepository */
        $auditRepository = $entityManager->getRepository(AuditEntry::class);

        /** @var list<AuditEntry> $entries */
        $entries = $auditRepository->findBy(
            ['entityClass' => Post::class, 'entityId' => (string) $post->getId(), 'action' => 'create'],
            ['id' => 'DESC'],
        );

        self::assertNotEmpty($entries, 'Expected audit entry for created post.');
    }

    public function testUnknownRoutesReturnNotFound(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/blog/category/does-not-exist');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request(Request::METHOD_GET, '/blog/tag/does-not-exist');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request(Request::METHOD_GET, '/blog/does-not-exist');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAuthenticatedCommentValidationAndReplyBranches(): void
    {
        $client = self::createClient();
        $author = UserFactory::createOne(['username' => 'reply-author'])->_real();
        $replier = UserFactory::createOne(['username' => 'reply-user'])->_real();

        $post = PostFactory::createOne([
            'title' => 'Reply target',
            'slug' => 'reply-target',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
            'createdBy' => $author,
        ])->_real();

        $parent = PostCommentFactory::createOne([
            'post' => $post,
            'author' => $author,
            'createdBy' => $author,
            'content' => 'Parent comment',
        ])->_real();

        $client->loginUser($replier);

        $crawler = $client->request(Request::METHOD_GET, '/blog/reply-target');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $client->request(Request::METHOD_POST, '/blog/reply-target/comments', [
            'content' => '',
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/blog/reply-target');

        $client->request(Request::METHOD_POST, '/blog/reply-target/comments', [
            'content' => 'Invalid csrf',
            '_token' => 'bad-token',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request(Request::METHOD_POST, '/blog/reply-target/comments', [
            'content' => 'Nested reply',
            'parent_id' => $parent->getId(),
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/blog/reply-target', Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Nested reply');
    }
}
