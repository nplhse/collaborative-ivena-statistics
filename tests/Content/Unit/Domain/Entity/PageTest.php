<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain\Entity;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Shared\Application\Locale\SupportedLocales;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testAddChildRegistersParentAndIsIdempotent(): void
    {
        $parent = new Page();
        $child = new Page();

        $parent->addChild($child);

        self::assertTrue($parent->getChildren()->contains($child));
        self::assertSame($parent, $child->getParent());
        self::assertCount(1, $parent->getChildren());

        $parent->addChild($child);

        self::assertCount(1, $parent->getChildren());
    }

    public function testRemoveChildDetachesParentAndSecondRemoveIsNoOp(): void
    {
        $parent = new Page();
        $child = new Page();

        $parent->addChild($child);
        $parent->removeChild($child);

        self::assertCount(0, $parent->getChildren());
        self::assertNull($child->getParent());

        $parent->removeChild($child);

        self::assertCount(0, $parent->getChildren());
    }

    public function testToStringFallsBackToUntitledWhenNoTitleAvailable(): void
    {
        self::assertSame('Untitled page', (string) new Page());
    }

    public function testToStringUsesTranslationTitle(): void
    {
        $page = new Page();
        $page->addTranslation($this->makeTranslation('About us', PageTranslation::STATUS_PUBLISHED));

        self::assertSame('About us', (string) $page);
    }

    public function testHasPublishedTranslation(): void
    {
        $page = new Page();
        self::assertFalse($page->hasPublishedTranslation());

        $translation = $this->makeTranslation('Draft', PageTranslation::STATUS_DRAFT);
        $page->addTranslation($translation);
        self::assertFalse($page->hasPublishedTranslation());

        $translation->setStatus(PageTranslation::STATUS_PUBLISHED);
        self::assertTrue($page->hasPublishedTranslation());
    }

    private function makeTranslation(string $title, string $status): PageTranslation
    {
        return new PageTranslation()
            ->setLocale(SupportedLocales::DEFAULT)
            ->setTitle($title)
            ->setSlug('about-us')
            ->setPath('/about-us')
            ->setStatus($status)
            ->setContent([]);
    }
}
