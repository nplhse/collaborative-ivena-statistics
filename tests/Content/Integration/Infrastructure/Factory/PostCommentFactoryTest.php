<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Infrastructure\Factory;

use App\Content\Infrastructure\Factory\PostCategoryFactory;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PostCommentFactoryTest extends KernelTestCase
{
    use Factories;

    public function testDefaultsContainExpectedKeys(): void
    {
        self::bootKernel();

        PostCategoryFactory::createOne();
        PostFactory::createOne();
        $factory = PostCommentFactory::new();

        $reflection = new \ReflectionMethod($factory, 'defaults');

        /** @var array<string, mixed> $defaults */
        $defaults = $reflection->invoke($factory);

        self::assertArrayHasKey('author', $defaults);
        self::assertArrayHasKey('content', $defaults);
        self::assertArrayHasKey('createdAt', $defaults);
        self::assertArrayHasKey('createdBy', $defaults);
        self::assertArrayHasKey('post', $defaults);
        self::assertInstanceOf(\DateTimeImmutable::class, $defaults['createdAt']);
    }
}
