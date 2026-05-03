<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain\Entity;

use App\Content\Domain\Entity\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testAddChildRegistersParentAndIsIdempotent(): void
    {
        $parent = $this->makePage('parent-one');
        $child = $this->makePage('child-one');

        $parent->addChild($child);

        self::assertTrue($parent->getChildren()->contains($child));
        self::assertSame($parent, $child->getParent());
        self::assertCount(1, $parent->getChildren());

        $parent->addChild($child);

        self::assertCount(1, $parent->getChildren());
    }

    public function testRemoveChildDetachesParentAndSecondRemoveIsNoOp(): void
    {
        $parent = $this->makePage('parent-two');
        $child = $this->makePage('child-two');

        $parent->addChild($child);
        $parent->removeChild($child);

        self::assertCount(0, $parent->getChildren());
        self::assertNull($child->getParent());

        $parent->removeChild($child);

        self::assertCount(0, $parent->getChildren());
    }

    public function testToStringFallsBackToUntitledWhenTitleUnset(): void
    {
        $page = new Page();
        $page
            ->setSlug('fresh-slug')
            ->setPath('/fresh-slug')
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setVisibility(Page::VISIBILITY_PUBLIC)
            ->setContent([['type' => 'richtext', 'data' => ['html' => '<p>x</p>']]])
        ;

        self::assertSame('Untitled page', (string) $page);

        $page->setTitle('Titel');
        self::assertSame('Titel', (string) $page);
    }

    public function testIsPublished(): void
    {
        $draft = $this->makePage('drafty');
        $draft->setStatus(Page::STATUS_DRAFT);
        self::assertFalse($draft->isPublished());

        $live = $this->makePage('livey');
        $live->setStatus(Page::STATUS_PUBLISHED);
        self::assertTrue($live->isPublished());
    }

    private function makePage(string $slug): Page
    {
        $page = new Page();
        $page
            ->setTitle(ucfirst(str_replace('-', ' ', $slug)))
            ->setSlug($slug)
            ->setPath('/'.$slug)
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setVisibility(Page::VISIBILITY_PUBLIC)
            ->setContent([
                [
                    'type' => 'richtext',
                    'data' => ['html' => '<p>x</p>'],
                ],
            ])
        ;

        return $page;
    }
}
