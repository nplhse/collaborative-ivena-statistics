<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain;

use App\Content\Domain\Entity\PageTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PageTranslationDraftTest extends TestCase
{
    use ClockSensitiveTrait;

    private const array SAVED = [['type' => 'quote', 'data' => ['text' => 'Saved']]];
    private const array DRAFT = [['type' => 'quote', 'data' => ['text' => '']]];

    public function testEditorUsesTheSavedContentWithoutDraft(): void
    {
        $translation = new PageTranslation()->setContent(self::SAVED);

        self::assertFalse($translation->hasDraft());
        self::assertSame(self::SAVED, $translation->getEditorContent());
        self::assertSame(0, $translation->getDraftRevision());
    }

    public function testDraftIsKeptApartFromTheSavedContent(): void
    {
        self::mockTime('2026-11-14 10:00:00');
        $translation = new PageTranslation()->setContent(self::SAVED);

        $translation->saveDraft(self::DRAFT);

        self::assertSame(self::SAVED, $translation->getContent());
        self::assertSame(self::DRAFT, $translation->getEditorContent());
        self::assertSame(1, $translation->getDraftRevision());
        self::assertSame('2026-11-14 10:00:00', $translation->getDraftUpdatedAt()?->format('Y-m-d H:i:s'));
    }

    public function testDiscardAndExplicitSaveEndTheDraftWithANewRevision(): void
    {
        $translation = new PageTranslation()->setContent(self::SAVED);
        $translation->saveDraft(self::DRAFT);

        $translation->discardDraft();
        self::assertFalse($translation->hasDraft());
        self::assertNull($translation->getDraftUpdatedAt());
        self::assertSame(2, $translation->getDraftRevision());

        $translation->saveDraft(self::DRAFT);
        $translation->setEditorContent(self::DRAFT);
        $translation->markSaved();
        self::assertSame(self::DRAFT, $translation->getContent());
        self::assertFalse($translation->hasDraft());
        self::assertSame(4, $translation->getDraftRevision());
    }
}
