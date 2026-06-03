<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Blog;

use App\Content\Application\Blog\PostContentSanitizer;
use App\Content\Application\Media\MediaImageFigureHtmlBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostContentSanitizerTest extends KernelTestCase
{
    public function testSanitizerDecodesEntityEncodedImageSnippet(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PostContentSanitizer::class);

        $raw = '<p>Intro</p>&lt;a href=&quot;/uploads/media/sample.png&quot; data-fslightbox=&quot;content-gallery&quot;&gt;&lt;img src=&quot;/uploads/media/sample.png&quot; alt=&quot;Sample&quot;&gt;&lt;/a&gt;';

        $html = $sut->sanitize($raw);

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('/uploads/media/sample.png', $html);
        self::assertStringContainsString('data-fslightbox="content-gallery"', $html);
        self::assertStringNotContainsString('&lt;img', $html);
    }

    public function testSanitizerKeepsPlainImageTag(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PostContentSanitizer::class);

        $raw = '<p>Text</p><img src="/uploads/media/sample.png" alt="Sample">';

        $html = $sut->sanitize($raw);

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('/uploads/media/sample.png', $html);
        self::assertStringContainsString('alt="Sample"', $html);
    }

    public function testSanitizerKeepsFigureWithLayoutClasses(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PostContentSanitizer::class);

        $raw = new MediaImageFigureHtmlBuilder()->build(
            '/uploads/media/sample.png',
            'Sample',
            'md',
            'left',
        );

        $html = $sut->sanitize('<p>Intro</p>'.$raw);

        self::assertStringContainsString('<figure', $html);
        self::assertStringContainsString('page-content-image--size-md', $html);
        self::assertStringContainsString('page-content-image--float-left', $html);
        self::assertStringContainsString('float-start', $html);
    }
}
