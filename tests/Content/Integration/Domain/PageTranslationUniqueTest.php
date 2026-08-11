<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Domain;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\Shared\Application\Locale\SupportedLocales;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageTranslationUniqueTest extends KernelTestCase
{
    use Factories;

    public function testDuplicateLocaleOnSamePageIsRejected(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'unique-locale-page',
            'path' => '/unique-locale-page',
        ]);

        self::assertTrue($page->hasTranslation(SupportedLocales::DEFAULT));

        $duplicate = new PageTranslation();
        $duplicate->setLocale(SupportedLocales::DEFAULT);
        $duplicate->setTitle('Duplicate');
        $duplicate->setSlug('duplicate-locale');
        $duplicate->setPath('/duplicate-locale-path');
        $duplicate->setStatus(PageTranslation::STATUS_DRAFT);
        $duplicate->setContent([]);
        $page->addTranslation($duplicate);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testSamePathAcrossLocalesIsRejected(): void
    {
        PageTranslationFactory::createOne([
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Collision EN',
            'slug' => 'path-collision',
            'path' => '/path-collision',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        // Same slug on a root page synchronizes to the same path → unique violation.
        PageTranslationFactory::createOne([
            'locale' => SupportedLocales::GERMAN,
            'title' => 'Collision DE',
            'slug' => 'path-collision',
            'path' => '/path-collision',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);
    }

    public function testDifferentPathsForDifferentLocalesAreAllowed(): void
    {
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'privacy-root',
            'path' => '/privacy-root-legacy',
            'status' => Page::STATUS_PUBLISHED,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Privacy',
            'slug' => 'privacy',
            'path' => '/privacy',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'Datenschutz',
            'slug' => 'datenschutz',
            'path' => '/datenschutz',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        self::assertTrue($page->hasTranslation('en'));
        self::assertTrue($page->hasTranslation('de'));
    }
}
