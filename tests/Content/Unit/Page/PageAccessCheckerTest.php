<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageAccessChecker;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class PageAccessCheckerTest extends TestCase
{
    public function testDraftTranslationIsNotViewable(): void
    {
        $page = new Page()->setVisibility(Page::VISIBILITY_PUBLIC);
        $draft = new PageTranslation()->setStatus(PageTranslation::STATUS_DRAFT);

        $checker = new PageAccessChecker($this->security(false));

        self::assertFalse($checker->canView($page, $draft));
    }

    public function testPageWithoutPublishedTranslationIsNotViewable(): void
    {
        $page = new Page()->setVisibility(Page::VISIBILITY_PUBLIC);
        $page->addTranslation(new PageTranslation()->setStatus(PageTranslation::STATUS_DRAFT)->setLocale('en'));

        $checker = new PageAccessChecker($this->security(false));

        self::assertFalse($checker->canView($page));
    }

    public function testPublicPublishedTranslationIsViewable(): void
    {
        $page = new Page()->setVisibility(Page::VISIBILITY_PUBLIC);
        $published = new PageTranslation()->setStatus(PageTranslation::STATUS_PUBLISHED);

        $checker = new PageAccessChecker($this->security(false));

        self::assertTrue($checker->canView($page, $published));

        $page->addTranslation($published);
        self::assertTrue($checker->canView($page));
    }

    public function testAuthenticatedVisibilityRequiresRoleUser(): void
    {
        $page = new Page()->setVisibility(Page::VISIBILITY_AUTHENTICATED);
        $published = new PageTranslation()->setStatus(PageTranslation::STATUS_PUBLISHED);

        self::assertFalse(new PageAccessChecker($this->security(false))->canView($page, $published));
        self::assertTrue(new PageAccessChecker($this->security(true))->canView($page, $published));
    }

    public function testUnknownVisibilityIsDenied(): void
    {
        $page = new Page()->setVisibility('private');
        $published = new PageTranslation()->setStatus(PageTranslation::STATUS_PUBLISHED);

        $checker = new PageAccessChecker($this->security(true));

        self::assertFalse($checker->canView($page, $published));
    }

    private function security(bool $granted): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);

        return $security;
    }
}
