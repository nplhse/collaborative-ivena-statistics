<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\BackfillPageTranslationsService;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Repository\PageRepository;
use App\Shared\Application\Locale\SupportedLocales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillPageTranslationsServiceTest extends KernelTestCase
{
    use Factories;

    public function testBackfillCreatesDefaultLocaleTranslationFromLegacyFields(): void
    {
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Legacy Title',
            'slug' => 'legacy-title',
            'path' => '/legacy-title',
            'status' => Page::STATUS_PUBLISHED,
            'content' => [
                ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Legacy</p>']],
            ],
        ]);

        /** @var BackfillPageTranslationsService $service */
        $service = self::getContainer()->get(BackfillPageTranslationsService::class);
        $result = $service->backfill(false);

        self::assertSame(1, $result->created);
        self::assertSame(0, $result->skipped);
        self::assertSame(0, $result->errors);

        /** @var PageRepository $pages */
        $pages = self::getContainer()->get(PageRepository::class);
        $reloaded = $pages->find($page->getId());
        self::assertInstanceOf(Page::class, $reloaded);

        $translation = $reloaded->translation(SupportedLocales::DEFAULT);
        self::assertInstanceOf(PageTranslation::class, $translation);
        self::assertSame('Legacy Title', $translation->getTitle());
        self::assertSame('/legacy-title', $translation->getPath());
        self::assertTrue($translation->isPublished());
    }

    public function testBackfillIsIdempotentAndSkipsExisting(): void
    {
        PageFactory::createOne([
            'title' => 'Already Translated',
            'slug' => 'already-translated',
            'path' => '/already-translated',
        ]);

        /** @var BackfillPageTranslationsService $service */
        $service = self::getContainer()->get(BackfillPageTranslationsService::class);

        $first = $service->backfill(false);
        $second = $service->backfill(false);

        self::assertSame(0, $first->created);
        self::assertGreaterThanOrEqual(1, $first->skipped);
        self::assertSame(0, $second->created);
        self::assertGreaterThanOrEqual(1, $second->skipped);
        self::assertSame(0, $second->errors);
    }

    public function testDryRunDoesNotPersist(): void
    {
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Dry Run',
            'slug' => 'dry-run-page',
            'path' => '/dry-run-page',
        ]);

        /** @var BackfillPageTranslationsService $service */
        $service = self::getContainer()->get(BackfillPageTranslationsService::class);
        $result = $service->backfill(true);

        self::assertSame(1, $result->created);

        /** @var PageRepository $pages */
        $pages = self::getContainer()->get(PageRepository::class);
        $reloaded = $pages->find($page->getId());
        self::assertInstanceOf(Page::class, $reloaded);
        self::assertFalse($reloaded->hasTranslation(SupportedLocales::DEFAULT));
    }

    public function testBackfillReportsMissingLegacyFieldsAsErrors(): void
    {
        PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => '',
            'slug' => 'missing-title',
            'path' => '/missing-title',
            'status' => Page::STATUS_DRAFT,
        ]);

        /** @var BackfillPageTranslationsService $service */
        $service = self::getContainer()->get(BackfillPageTranslationsService::class);
        $result = $service->backfill(false);

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->errors);
        self::assertNotEmpty($result->errorMessages);
        self::assertStringContainsString('missing legacy title/slug/path', $result->errorMessages[0]);
    }
}
