<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Media;

use App\Content\Application\Contract\MediaUrlRewriterInterface;
use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Rewriting old media URLs in content (ADR 039). Fixture: photo-a.png has moved to the storage, other.pdf has no
 * media item. The post holds three old URLs (two of the moved file, one unknown), the page one in its content and
 * one in its draft.
 */
#[ResetDatabase]
final class MediaUrlRewriterTest extends KernelTestCase
{
    use Factories;

    private const string KEY = 'originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png';
    private const string NEW_URL = '/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png';

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testReplacesTheUrlsOfMovedMediaAndKeepsTheOthers(): void
    {
        [$postId, $pageTranslationId, $postUpdatedAt] = $this->fixture();

        $result = $this->rewriter()->rewrite(false);

        self::assertSame(4, $result->rewrittenUrls, 'two in the post, one in the page, one in its draft');
        self::assertSame(['post' => 1, 'page' => 1, 'page draft' => 1], $result->changedRecords);
        self::assertSame(['other.pdf'], $result->unresolved);

        $post = $this->connection()->fetchAssociative('SELECT content, updated_at FROM post WHERE id = ?', [$postId]);
        self::assertIsArray($post);
        self::assertSame(
            '<p><img src="'.self::NEW_URL.'" alt="A"> <a href="https://example.org'.self::NEW_URL.'?download=1">A</a> <a href="/uploads/media/other.pdf">B</a></p>',
            $post['content'],
        );
        self::assertSame($postUpdatedAt, $post['updated_at'], 'a technical rewrite is not an edit');

        $page = $this->connection()->fetchAssociative('SELECT content::text AS content, draft_content::text AS draft FROM page_translation WHERE id = ?', [$pageTranslationId]);
        self::assertIsArray($page);
        self::assertStringContainsString(str_replace('/', '\/', self::NEW_URL), (string) $page['content']);
        self::assertStringNotContainsString('uploads', (string) $page['content']);
        self::assertStringContainsString(str_replace('/', '\/', self::NEW_URL), (string) $page['draft']);
    }

    public function testDryRunChangesNothingAndASecondRunFindsNothing(): void
    {
        [$postId] = $this->fixture();
        $before = $this->connection()->fetchOne('SELECT content FROM post WHERE id = ?', [$postId]);

        self::assertSame(4, $this->rewriter()->rewrite(true)->rewrittenUrls);
        self::assertSame($before, $this->connection()->fetchOne('SELECT content FROM post WHERE id = ?', [$postId]));

        $this->rewriter()->rewrite(false);
        $second = $this->rewriter()->rewrite(false);
        self::assertSame(0, $second->rewrittenUrls);
        self::assertSame(['other.pdf'], $second->unresolved);
    }

    /**
     * @return array{int, int, ?string}
     */
    private function fixture(): array
    {
        $media = MediaFactory::new()->legacy('photo-a.png')->create();
        $this->moveTo($media, self::KEY);

        $post = PostFactory::createOne([
            'content' => '<p><img src="/uploads/media/photo-a.png" alt="A"> <a href="https://example.org/uploads/media/photo-a.png?download=1">A</a> <a href="/uploads/media/other.pdf">B</a></p>',
        ]);
        $this->connection()->executeStatement("UPDATE post SET updated_at = '2026-01-02 03:04:05' WHERE id = ?", [$post->getId()]);

        $page = PageFactory::createOne([
            'slug' => 'rewrite-urls',
            'content' => [['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<img src="/uploads/media/photo-a.png" alt="A">']]],
        ]);
        $translationId = (int) $this->connection()->fetchOne('SELECT id FROM page_translation WHERE page_id = ? ORDER BY id LIMIT 1', [$page->getId()]);
        $this->connection()->executeStatement(
            'UPDATE page_translation SET draft_content = ? WHERE id = ?',
            [json_encode([['type' => 'richtext', 'data' => ['html' => '<p><img src="/uploads/media/photo-a.png"></p>']]], \JSON_THROW_ON_ERROR), $translationId],
        );

        $updatedAt = $this->connection()->fetchOne('SELECT updated_at FROM post WHERE id = ?', [$post->getId()]);

        return [(int) $post->getId(), $translationId, \is_string($updatedAt) ? $updatedAt : null];
    }

    private function moveTo(Media $media, string $key): void
    {
        $media->attachStoredFile($key, 68, str_repeat('a', 64), new \DateTimeImmutable('2026-11-21'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function rewriter(): MediaUrlRewriterInterface
    {
        return self::getContainer()->get(MediaUrlRewriterInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
