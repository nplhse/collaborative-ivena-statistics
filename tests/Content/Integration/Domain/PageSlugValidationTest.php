<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Domain;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Shared\Application\Locale\SupportedLocales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageSlugValidationTest extends KernelTestCase
{
    use Factories;

    private ValidatorInterface $validator;

    private PagePathResolver $pathResolver;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
        $this->pathResolver = self::getContainer()->get(PagePathResolver::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testValidManualSlugPassesValidationAfterSynchronize(): void
    {
        $translation = $this->createTranslation('my-valid-page');

        $this->pathResolver->synchronize($translation);

        $violations = $this->validator->validate($translation);

        self::assertCount(0, $violations);
        self::assertSame('my-valid-page', $translation->getSlug());
        self::assertSame('/my-valid-page', $translation->getPath());
    }

    public function testInvalidSlugFormatFailsValidation(): void
    {
        $translation = $this->createTranslation('Invalid Slug!');

        $this->pathResolver->synchronize($translation);

        $violations = $this->validator->validate($translation);

        self::assertNotEmpty($violations);
        self::assertSame('slug', (string) $violations->get(0)->getPropertyPath());
        self::assertSame('Invalid Slug!', $translation->getSlug());
    }

    public function testSlugExceedingMaxLengthFailsValidation(): void
    {
        $translation = $this->createTranslation(str_repeat('a', 181));

        $this->pathResolver->synchronize($translation);

        $violations = $this->validator->validate($translation);

        self::assertNotEmpty($violations);
        self::assertSame('slug', (string) $violations->get(0)->getPropertyPath());
    }

    public function testDuplicatePathUnderSameLocaleFailsValidation(): void
    {
        $parent = PageFactory::createOne([
            'title' => 'Parent',
            'slug' => 'parent-page',
            'parent' => null,
        ]);

        PageFactory::createOne([
            'title' => 'First Child',
            'slug' => 'child-slug',
            'parent' => $parent,
        ]);

        $duplicatePage = new Page();
        $duplicatePage->setParent($parent);
        $duplicatePage->setVisibility(Page::VISIBILITY_PUBLIC);

        $duplicate = new PageTranslation()
            ->setLocale(SupportedLocales::DEFAULT)
            ->setTitle('Second Child')
            ->setSlug('child-slug')
            ->setStatus(PageTranslation::STATUS_DRAFT)
            ->setContent([]);
        $duplicatePage->addTranslation($duplicate);

        $this->pathResolver->synchronize($duplicate);
        $this->entityManager->persist($duplicatePage);
        $this->entityManager->persist($duplicate);

        $violations = $this->validator->validate($duplicate);

        self::assertGreaterThan(0, $violations->count());

        $propertyPaths = [];
        foreach ($violations as $violation) {
            $propertyPaths[] = (string) $violation->getPropertyPath();
        }

        self::assertContains('path', $propertyPaths);
    }

    private function createTranslation(string $slug): PageTranslation
    {
        $page = new Page();
        $page->setVisibility(Page::VISIBILITY_PUBLIC);

        $translation = new PageTranslation()
            ->setLocale(SupportedLocales::DEFAULT)
            ->setTitle('Validation Test')
            ->setSlug($slug)
            ->setStatus(PageTranslation::STATUS_DRAFT)
            ->setContent([
                [
                    'type' => 'richtext',
                    'data' => ['html' => '<p>Test</p>'],
                ],
            ]);
        $page->addTranslation($translation);

        return $translation;
    }
}
