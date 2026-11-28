<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Blog;

use App\Content\Application\Blog\PostContentSanitizer;
use App\Content\Application\Media\MediaImageFigureHtmlBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves that the `page_richtext` sanitizer configuration keeps the media snippets of blog posts; decoding and the
 * previews are covered by {@see \App\Tests\Content\Unit\Blog\PostContentSanitizerTest}.
 */
final class PostContentSanitizerTest extends KernelTestCase
{
    private PostContentSanitizer $sanitizer;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->sanitizer = self::getContainer()->get(PostContentSanitizer::class);
    }

    public function testKeepsAnEntityEncodedLightboxImageSnippet(): void
    {
        $raw = '<p>Intro</p>&lt;a href=&quot;/uploads/media/sample.png&quot; data-fslightbox=&quot;content-gallery&quot;&gt;&lt;img src=&quot;/uploads/media/sample.png&quot; alt=&quot;Sample&quot;&gt;&lt;/a&gt;';

        $html = $this->sanitizer->sanitize($raw);

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('/uploads/media/sample.png', $html);
        self::assertStringContainsString('alt="Sample"', $html);
        self::assertStringContainsString('data-fslightbox="content-gallery"', $html);
        self::assertStringNotContainsString('&lt;img', $html);
    }

    public function testKeepsTheFigureWithItsLayoutClasses(): void
    {
        $figure = new MediaImageFigureHtmlBuilder()->build('/uploads/media/sample.png', 'Sample', 'md', 'left');

        $html = $this->sanitizer->sanitize('<p>Intro</p>'.$figure);

        self::assertStringContainsString('<figure', $html);
        self::assertStringContainsString('page-content-image--size-md', $html);
        self::assertStringContainsString('page-content-image--float-left', $html);
        self::assertStringContainsString('float-start', $html);
    }
}
