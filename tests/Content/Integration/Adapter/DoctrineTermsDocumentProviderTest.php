<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Adapter;

use App\Content\Application\Page\PageTranslationResolver;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Adapter\DoctrineTermsDocumentProvider;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Contract\TermsDocumentProviderInterface;
use App\User\Application\Terms\TermsDocument;

/** The published terms page and the hash stored with each acceptance (#1564, ADR 043). */
final class DoctrineTermsDocumentProviderTest extends DatabaseKernelTestCase
{
    private const array CONTENT = [
        ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Terms, version 1</p>']],
    ];

    public function testReturnsThePublishedTermsPageWithTheHashOfItsContent(): void
    {
        $locale = $this->fallbackLocale();
        $page = PageFactory::new()->withoutDefaultTranslation()->create(['key' => PageKey::Terms]);
        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => $locale,
            'title' => 'Terms of use',
            'slug' => 'terms',
            'path' => '/terms',
            'content' => self::CONTENT,
        ]);

        $document = $this->provider()->current($locale);

        self::assertInstanceOf(TermsDocument::class, $document);
        self::assertSame($locale, $document->locale);
        self::assertSame(DoctrineTermsDocumentProvider::contentHash(self::CONTENT), $document->contentHash);
        self::assertSame(64, \strlen($document->contentHash));
    }

    public function testFallsBackToTheContentDefaultLanguageLikeThePageItself(): void
    {
        $fallback = $this->fallbackLocale();
        $other = 'de' === $fallback ? 'en' : 'de';
        $page = PageFactory::new()->withoutDefaultTranslation()->create(['key' => PageKey::Terms]);
        PageTranslationFactory::createOne(['page' => $page, 'locale' => $fallback, 'content' => self::CONTENT]);

        $document = $this->provider()->current($other);

        self::assertInstanceOf(TermsDocument::class, $document);
        self::assertSame($fallback, $document->locale, 'the language actually shown is stored');
    }

    public function testReturnsNullWithoutAPublishedTermsPage(): void
    {
        self::assertNull($this->provider()->current($this->fallbackLocale()), 'no terms page');

        $page = PageFactory::new()->withoutDefaultTranslation()->create(['key' => PageKey::Terms]);
        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => $this->fallbackLocale(),
            'status' => PageTranslation::STATUS_DRAFT,
        ]);

        self::assertNull($this->provider()->current($this->fallbackLocale()), 'draft only');
    }

    public function testOtherPagesAreNotTheTerms(): void
    {
        PageFactory::createOne(['key' => PageKey::Privacy, 'locale' => $this->fallbackLocale()]);

        self::assertNull($this->provider()->current($this->fallbackLocale()));
    }

    private function provider(): TermsDocumentProviderInterface
    {
        $provider = self::getContainer()->get(TermsDocumentProviderInterface::class);
        self::assertInstanceOf(DoctrineTermsDocumentProvider::class, $provider);

        return $provider;
    }

    private function fallbackLocale(): string
    {
        return self::getContainer()->get(PageTranslationResolver::class)->getContentDefaultLocale();
    }
}
