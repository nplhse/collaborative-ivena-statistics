<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PagePathSynchronizationValidationTest extends KernelTestCase
{
    public function testPathNotBlankPassesAfterSynchronize(): void
    {
        self::bootKernel();

        /** @var PagePathResolver $resolver */
        $resolver = self::getContainer()->get(PagePathResolver::class);
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $page = new Page();
        $translation = new PageTranslation()
            ->setLocale('en')
            ->setTitle('Synced Page')
            ->setSlug('synced-page')
            ->setStatus(PageTranslation::STATUS_DRAFT)
            ->setContent([]);
        $page->addTranslation($translation);

        $resolver->synchronize($translation);

        $violations = $validator->validate($translation);
        self::assertCount(0, $violations);
        self::assertSame('/synced-page', $translation->getPath());
    }

    public function testEmptySlugIsGeneratedFromTitleBeforeValidation(): void
    {
        self::bootKernel();

        /** @var PagePathResolver $resolver */
        $resolver = self::getContainer()->get(PagePathResolver::class);
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $page = new Page();
        $translation = new PageTranslation()
            ->setLocale('en')
            ->setTitle('From Title Only')
            ->setSlug('')
            ->setStatus(PageTranslation::STATUS_DRAFT)
            ->setContent([]);
        $page->addTranslation($translation);

        $resolver->synchronize($translation);

        $violations = $validator->validate($translation);
        self::assertCount(0, $violations);
        self::assertSame('from-title-only', $translation->getSlug());
        self::assertSame('/from-title-only', $translation->getPath());
    }
}
