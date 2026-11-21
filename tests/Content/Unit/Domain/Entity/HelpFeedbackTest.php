<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain\Entity;

use App\Content\Domain\Entity\HelpFeedback;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageType;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class HelpFeedbackTest extends TestCase
{
    public function testANewAnswerWasUpdatedWhenItWasGiven(): void
    {
        $page = new Page();
        $user = new User();
        $at = new \DateTimeImmutable('2026-11-21 10:00:00');

        $feedback = new HelpFeedback($page, $user, true, 'de', $at);

        self::assertNull($feedback->getId());
        self::assertSame($page, $feedback->getPage());
        self::assertSame($user, $feedback->getUser());
        self::assertTrue($feedback->isHelpful());
        self::assertSame('de', $feedback->getLocale());
        self::assertSame($at, $feedback->getCreatedAt());
        self::assertSame($at, $feedback->getUpdatedAt());
    }

    public function testAnsweringAgainChangesAnswerLanguageAndTime(): void
    {
        $createdAt = new \DateTimeImmutable('2026-11-21 10:00:00');
        $changedAt = new \DateTimeImmutable('2026-11-22 09:00:00');
        $feedback = new HelpFeedback(new Page(), new User(), true, 'de', $createdAt);

        $feedback->answer(false, 'en', $changedAt);

        self::assertFalse($feedback->isHelpful());
        self::assertSame('en', $feedback->getLocale());
        self::assertSame($createdAt, $feedback->getCreatedAt());
        self::assertSame($changedAt, $feedback->getUpdatedAt());
    }

    public function testPageTypesAndSearchTextOfTranslations(): void
    {
        self::assertSame('page.type.help', PageType::Help->translationKey());
        self::assertSame('page.type.page', PageType::Page->translationKey());

        $translation = new PageTranslation();
        self::assertSame('', $translation->getSearchText());
        $translation->setContent([['type' => 'headline', 'data' => ['text' => 'Saved']]]);
        self::assertSame('Saved', $translation->getSearchText());
        $translation->setEditorContent([['type' => 'richtext', 'data' => ['html' => '<p>Edited</p>']]]);
        self::assertSame('Edited', $translation->getSearchText());
        $translation->saveDraft([['type' => 'richtext', 'data' => ['html' => '<p>Draft</p>']]]);
        self::assertSame('Edited', $translation->getSearchText(), 'drafts are not searched');
    }
}
