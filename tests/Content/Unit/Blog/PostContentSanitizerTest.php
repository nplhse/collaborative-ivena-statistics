<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Blog;

use App\Content\Application\Blog\PostContentSanitizer;
use App\Content\Application\Media\MediaImageFigureHtmlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Entity decoding and the previews of {@see PostContentSanitizer}. The HTML sanitizer is a stub that returns its input
 * unchanged unless a test says otherwise; what the configured sanitizer keeps is covered by
 * {@see \App\Tests\Content\Integration\Blog\PostContentSanitizerTest}.
 */
final class PostContentSanitizerTest extends TestCase
{
    public function testDecodesEntitiesBeforeSanitizing(): void
    {
        $sanitizer = $this->sanitizer();

        $html = $sanitizer->sanitize('<p>Intro</p>&lt;img src=&quot;/uploads/media/sample.png&quot; alt=&quot;Sample&quot;&gt;');

        self::assertSame('<p>Intro</p><img src="/uploads/media/sample.png" alt="Sample">', $html);
    }

    public function testEmptyContentIsNotSanitized(): void
    {
        $html = $this->createMock(HtmlSanitizerInterface::class);
        $html->expects(self::never())->method('sanitize');

        $result = new PostContentSanitizer($html)->sanitize('');

        self::assertSame('', $result);
    }

    public function testPreviewIsTakenFromTheSanitizedContent(): void
    {
        $sanitizer = $this->sanitizer(static fn (): string => '<p>Sanitized</p>');

        $preview = $sanitizer->preview('<p>Raw</p><script>alert(1)</script>');

        self::assertSame('<p>Sanitized</p>', $preview);
    }

    public function testPreviewKeepsOnlyTheFirstParagraph(): void
    {
        $sanitizer = $this->sanitizer();

        $preview = $sanitizer->preview('<p>Safe intro</p><p>More</p>');

        self::assertSame('<p>Safe intro</p>', $preview);
    }

    public function testPreviewTruncatesPlainTextWithoutParagraph(): void
    {
        $sanitizer = $this->sanitizer();

        $preview = $sanitizer->preview(str_repeat('a', PostContentSanitizer::PLAIN_PREVIEW_MAX_LENGTH + 40));

        self::assertSame(str_repeat('a', PostContentSanitizer::PLAIN_PREVIEW_MAX_LENGTH).'...', $preview);
    }

    public function testPreviewOfPlainTextStripsTagsAndEscapesTheText(): void
    {
        $sanitizer = $this->sanitizer();

        $preview = $sanitizer->preview('Fish <strong>&amp;</strong> chips');

        self::assertSame('Fish &amp; chips', $preview);
    }

    public function testPreviewOfEmptyContentIsEmpty(): void
    {
        $sanitizer = $this->sanitizer();

        $preview = $sanitizer->preview('');

        self::assertSame('', $preview);
    }

    public function testCompactPreviewOfEmptyContentIsEmpty(): void
    {
        $sanitizer = $this->sanitizer();

        $preview = $sanitizer->compactPreview('');

        self::assertSame('', $preview);
    }

    public function testCompactPreviewStripsImagesFromTheFirstParagraph(): void
    {
        $sanitizer = $this->sanitizer();
        $figure = new MediaImageFigureHtmlBuilder()->build('/uploads/media/sample.png', 'Sample', 'md', 'none');

        $preview = $sanitizer->compactPreview('<p>Safe intro <img src="/uploads/media/sample.png" alt="Sample"></p>'.$figure.'<p>More</p>');

        self::assertSame('<p>Safe intro </p>', $preview);
    }

    public function testCompactPreviewStripsFiguresFromTheFirstParagraph(): void
    {
        $sanitizer = $this->sanitizer();
        $figure = new MediaImageFigureHtmlBuilder()->build('/uploads/media/sample.png', 'Sample', 'md', 'none');

        $preview = $sanitizer->compactPreview('<p>Safe intro '.$figure.'</p>');

        self::assertSame('<p>Safe intro </p>', $preview);
    }

    public function testCompactPreviewStripsLinksLeftEmptyByTheImageRemoval(): void
    {
        $sanitizer = $this->sanitizer();

        $preview = $sanitizer->compactPreview('<p>Safe intro <a href="/uploads/media/sample.png"><img src="/uploads/media/sample.png" alt="Sample"></a></p>');

        self::assertSame('<p>Safe intro </p>', $preview);
    }

    public function testCompactPreviewIsEmptyWhenTheFirstParagraphIsOnlyMedia(): void
    {
        $sanitizer = $this->sanitizer();
        $figure = new MediaImageFigureHtmlBuilder()->build('/uploads/media/sample.png', 'Sample');

        $preview = $sanitizer->compactPreview('<p>'.$figure.'</p>');

        self::assertSame('', $preview);
    }

    /**
     * @param (\Closure(string): string)|null $sanitize
     */
    private function sanitizer(?\Closure $sanitize = null): PostContentSanitizer
    {
        $html = $this->createStub(HtmlSanitizerInterface::class);
        $html->method('sanitize')->willReturnCallback($sanitize ?? static fn (string $input): string => $input);

        return new PostContentSanitizer($html);
    }
}
