<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain\Entity;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Shared\Application\Locale\SupportedLocales;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

final class PageTranslationTest extends TestCase
{
    public function testToStringUsesTitleAndLocaleFallbacks(): void
    {
        $empty = new PageTranslation();
        self::assertSame('Untitled translation [?]', (string) $empty);

        $filled = new PageTranslation()
            ->setTitle('About')
            ->setLocale(SupportedLocales::GERMAN);
        self::assertSame('About [de]', (string) $filled);
    }

    public function testSetSlugNormalizesNullToEmptyString(): void
    {
        $translation = new PageTranslation()->setSlug(null);

        self::assertSame('', $translation->getSlug());
    }

    public function testIsPublished(): void
    {
        $translation = new PageTranslation();
        self::assertFalse($translation->isPublished());

        $translation->setStatus(PageTranslation::STATUS_PUBLISHED);
        self::assertTrue($translation->isPublished());
    }

    public function testUpdateTimestampsSetsUpdatedAt(): void
    {
        $translation = new PageTranslation();
        self::assertNull($translation->getUpdatedAt());

        $translation->updateTimestamps();

        self::assertInstanceOf(\DateTimeImmutable::class, $translation->getUpdatedAt());
    }

    public function testValidateParentTranslationAddsViolationWhenParentLocaleMissing(): void
    {
        $parent = new Page();
        $child = new Page()->setParent($parent);
        $translation = new PageTranslation()
            ->setLocale(SupportedLocales::GERMAN)
            ->setStatus(PageTranslation::STATUS_PUBLISHED)
            ->setTitle('Kind')
            ->setSlug('kind')
            ->setPath('/kind');
        $child->addTranslation($translation);

        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->expects(self::once())->method('atPath')->with('locale')->willReturnSelf();
        $builder->expects(self::once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('page_translation.validation.parent_translation_required')
            ->willReturn($builder);

        $translation->validateParentTranslation($context);
    }

    public function testValidateParentTranslationSkipsDraftAndRoots(): void
    {
        $root = new PageTranslation()
            ->setLocale(SupportedLocales::DEFAULT)
            ->setStatus(PageTranslation::STATUS_PUBLISHED);
        new Page()->addTranslation($root);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $root->validateParentTranslation($context);

        $parent = new Page();
        $parent->addTranslation(
            new PageTranslation()
                ->setLocale(SupportedLocales::GERMAN)
                ->setStatus(PageTranslation::STATUS_PUBLISHED)
                ->setTitle('Eltern')
                ->setSlug('eltern')
                ->setPath('/eltern'),
        );

        $draftChild = new PageTranslation()
            ->setLocale(SupportedLocales::GERMAN)
            ->setStatus(PageTranslation::STATUS_DRAFT);
        new Page()->setParent($parent)->addTranslation($draftChild);

        $draftChild->validateParentTranslation($context);
    }
}
