<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageContentMediaResolver;
use App\Content\Infrastructure\Factory\MediaFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageContentMediaResolverTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testImageBlockResolvesMediaIdToSrcAndAlt(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'resolved.png',
            'altText' => 'From media',
        ])->_real();

        $sut = self::getContainer()->get(PageContentMediaResolver::class);

        $resolved = $sut->resolve([
            [
                'type' => 'image',
                'data' => [
                    'mediaId' => $media->getId(),
                    'alt' => '',
                ],
            ],
        ]);

        self::assertSame('/uploads/media/resolved.png', $resolved[0]['data']['src']);
        self::assertSame('From media', $resolved[0]['data']['alt']);
    }

    public function testCtaMediaBlockResolvesPdfUrlAndOpensInNewTab(): void
    {
        self::bootKernel();

        $media = MediaFactory::new()->asPdf()->create([
            'filename' => 'report.pdf',
        ])->_real();

        $sut = self::getContainer()->get(PageContentMediaResolver::class);

        $resolved = $sut->resolve([
            [
                'type' => 'cta',
                'data' => [
                    'headline' => 'Download',
                    'buttonLabel' => 'PDF',
                    'linkType' => 'media',
                    'mediaId' => $media->getId(),
                ],
            ],
        ]);

        self::assertSame('/uploads/media/report.pdf', $resolved[0]['data']['buttonUrl']);
        self::assertTrue($resolved[0]['data']['openInNewTab']);
    }

    public function testLegacyImageWithSrcOnlyIsUnchanged(): void
    {
        self::bootKernel();

        $sut = self::getContainer()->get(PageContentMediaResolver::class);

        $resolved = $sut->resolve([
            [
                'type' => 'image',
                'data' => ['src' => '/legacy.jpg', 'alt' => 'Legacy'],
            ],
        ]);

        self::assertSame('/legacy.jpg', $resolved[0]['data']['src']);
    }
}
