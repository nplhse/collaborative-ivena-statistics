<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Domain;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
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
        $page = $this->createPage('my-valid-page');

        $this->pathResolver->synchronize($page);

        $violations = $this->validator->validate($page);

        self::assertCount(0, $violations);
        self::assertSame('my-valid-page', $page->getSlug());
        self::assertSame('/my-valid-page', $page->getPath());
    }

    public function testInvalidSlugFormatFailsValidation(): void
    {
        $page = $this->createPage('Invalid Slug!');

        $this->pathResolver->synchronize($page);

        $violations = $this->validator->validate($page);

        self::assertNotEmpty($violations);
        self::assertSame('slug', (string) $violations->get(0)->getPropertyPath());
        self::assertSame('Invalid Slug!', $page->getSlug());
    }

    public function testSlugExceedingMaxLengthFailsValidation(): void
    {
        $page = $this->createPage(str_repeat('a', 181));

        $this->pathResolver->synchronize($page);

        $violations = $this->validator->validate($page);

        self::assertNotEmpty($violations);
        self::assertSame('slug', (string) $violations->get(0)->getPropertyPath());
    }

    public function testDuplicateSlugUnderSameParentFailsValidation(): void
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

        $duplicate = $this->createPage('child-slug');
        $duplicate->setParent($parent);
        $this->pathResolver->synchronize($duplicate);
        $this->entityManager->persist($duplicate);

        $violations = $this->validator->validate($duplicate);

        self::assertGreaterThan(0, $violations->count());

        $propertyPaths = [];
        foreach ($violations as $violation) {
            $propertyPaths[] = (string) $violation->getPropertyPath();
        }

        self::assertTrue(
            in_array('slug', $propertyPaths, true) || in_array('path', $propertyPaths, true),
            'Expected a slug or path uniqueness violation.',
        );
    }

    private function createPage(string $slug): Page
    {
        $page = new Page();
        $page
            ->setTitle('Validation Test')
            ->setSlug($slug)
            ->setStatus(Page::STATUS_DRAFT)
            ->setVisibility(Page::VISIBILITY_PUBLIC)
            ->setContent([
                [
                    'type' => 'richtext',
                    'data' => ['html' => '<p>Test</p>'],
                ],
            ])
        ;

        return $page;
    }
}
