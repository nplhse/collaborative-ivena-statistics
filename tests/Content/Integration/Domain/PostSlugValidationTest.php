<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Domain;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PostSlugValidationTest extends KernelTestCase
{
    use Factories;

    private ValidatorInterface $validator;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testValidSlugPassesValidation(): void
    {
        $post = $this->createPostWithSlug('my-valid-slug');

        $violations = $this->validator->validate($post);

        self::assertCount(0, $violations);
    }

    public function testInvalidSlugFormatFailsValidation(): void
    {
        $post = $this->createPostWithSlug('Invalid Slug!');

        $violations = $this->validator->validate($post);

        self::assertNotEmpty($violations);
        self::assertSame('slug', (string) $violations->get(0)->getPropertyPath());
    }

    public function testSlugExceedingMaxLengthFailsValidation(): void
    {
        $post = $this->createPostWithSlug(str_repeat('a', 201));

        $violations = $this->validator->validate($post);

        self::assertNotEmpty($violations);
        self::assertSame('slug', (string) $violations->get(0)->getPropertyPath());
    }

    public function testDuplicateSlugFailsValidation(): void
    {
        PostFactory::createOne([
            'title' => 'Existing Post',
            'slug' => 'duplicate-slug',
        ]);

        $duplicate = $this->createPostWithSlug('duplicate-slug');
        $this->entityManager->persist($duplicate);

        $violations = $this->validator->validate($duplicate);

        self::assertGreaterThan(0, $violations->count());
        self::assertStringContainsString('slug', (string) $violations->get(0)->getPropertyPath());
    }

    private function createPostWithSlug(string $slug): Post
    {
        $category = PostCategoryFactory::createOne();
        $author = UserFactory::createOne();

        $post = new Post();
        $post
            ->setTitle('Validation Test')
            ->setSlug($slug)
            ->setContent('<p>Test</p>')
            ->setStatus(PostStatus::DRAFT)
            ->setCategory($category)
            ->setCreatedBy($author)
        ;

        return $post;
    }
}
