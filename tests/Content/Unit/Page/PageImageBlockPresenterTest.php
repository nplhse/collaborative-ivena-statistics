<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageImageBlockPresenter;
use PHPUnit\Framework\TestCase;

final class PageImageBlockPresenterTest extends TestCase
{
    public function testAutoSizeUsesNaturalWidthClasses(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'size' => 'auto',
            'width' => 400,
            'height' => 300,
        ]);

        self::assertContains('page-content-image--size-auto', $presentation->figureClasses);
        self::assertSame(400, $presentation->imgWidth);
        self::assertSame(300, $presentation->imgHeight);
    }

    public function testDefaultsMissingSizeToAuto(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
        ]);

        self::assertContains('page-content-image--size-auto', $presentation->figureClasses);
    }

    public function testLargeSizeKeepsFullWidthClass(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'size' => 'lg',
        ]);

        self::assertContains('page-content-image--size-lg', $presentation->figureClasses);
    }

    public function testMediumFloatedImageKeepsFloatClasses(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'size' => 'md',
            'float' => 'left',
        ]);

        self::assertContains('page-content-image--size-md', $presentation->figureClasses);
        self::assertContains('page-content-image--float-left', $presentation->figureClasses);
        self::assertContains('float-start', $presentation->figureClasses);
    }

    public function testMissingDimensionsAreIgnored(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'size' => 'auto',
        ]);

        self::assertNull($presentation->imgWidth);
        self::assertNull($presentation->imgHeight);
    }

    public function testLegacyWidthPresetMapsToSizeClass(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'widthPreset' => 'md',
        ]);

        self::assertContains('page-content-image--size-md', $presentation->figureClasses);
    }

    public function testLegacySmallWidthPresetMapsToSizeClass(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'widthPreset' => 'sm',
        ]);

        self::assertContains('page-content-image--size-sm', $presentation->figureClasses);
    }

    public function testLegacyLargeWidthPresetMapsToSizeClass(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'widthPreset' => 'lg',
        ]);

        self::assertContains('page-content-image--size-lg', $presentation->figureClasses);
    }

    public function testRightFloatAddsEndClasses(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'size' => 'sm',
            'float' => 'right',
        ]);

        self::assertContains('page-content-image--float-right', $presentation->figureClasses);
        self::assertContains('float-end', $presentation->figureClasses);
    }

    public function testInvalidFloatDefaultsToNone(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'float' => 'center',
        ]);

        self::assertNotContains('page-content-image--float-left', $presentation->figureClasses);
        self::assertNotContains('page-content-image--float-right', $presentation->figureClasses);
    }

    public function testStringDimensionsAreParsed(): void
    {
        $presentation = $this->presenter()->present([
            'src' => '/uploads/media/photo.png',
            'alt' => 'Photo',
            'width' => '640',
            'height' => '480',
        ]);

        self::assertSame(640, $presentation->imgWidth);
        self::assertSame(480, $presentation->imgHeight);
    }

    private function presenter(): PageImageBlockPresenter
    {
        return new PageImageBlockPresenter();
    }
}
