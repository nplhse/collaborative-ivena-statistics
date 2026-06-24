<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Slug;

use App\Content\Application\Slug\SlugGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class SlugGeneratorTest extends TestCase
{
    private SlugGenerator $generator;

    #[\Override]
    protected function setUp(): void
    {
        $this->generator = new SlugGenerator(new AsciiSlugger());
    }

    public function testNormalizeTitle(): void
    {
        self::assertSame(
            'my-very-long-example-title',
            $this->generator->normalize('My Very Long Example Title'),
        );
    }

    public function testNormalizeManualInput(): void
    {
        self::assertSame(
            'my-custom-slug',
            $this->generator->normalize('My Custom Slug!'),
        );
    }

    public function testTruncateToPostMaxLengthAndTrimTrailingHyphens(): void
    {
        $longInput = str_repeat('word-', 50).'end';

        $slug = $this->generator->normalize($longInput, SlugGenerator::MAX_LENGTH_POST);

        self::assertLessThanOrEqual(SlugGenerator::MAX_LENGTH_POST, strlen($slug));
        self::assertDoesNotMatchRegularExpression('/-$/', $slug);
    }

    public function testTruncateToPageMaxLength(): void
    {
        $longInput = str_repeat('segment-', 30).'tail';

        $slug = $this->generator->normalize($longInput, SlugGenerator::MAX_LENGTH_PAGE);

        self::assertLessThanOrEqual(SlugGenerator::MAX_LENGTH_PAGE, strlen($slug));
        self::assertDoesNotMatchRegularExpression('/-$/', $slug);
    }

    public function testEnsureUniqueAppendsNumericSuffixes(): void
    {
        $existing = ['taken-slug', 'taken-slug-2'];

        $slug = $this->generator->ensureUnique(
            'taken-slug',
            static fn (string $candidate): bool => in_array($candidate, $existing, true),
        );

        self::assertSame('taken-slug-3', $slug);
    }

    public function testEnsureUniqueRespectsMaxLengthWithSuffix(): void
    {
        $base = str_repeat('a', SlugGenerator::MAX_LENGTH_POST);

        $slug = $this->generator->ensureUnique(
            $base,
            static fn (string $candidate): bool => $candidate === $base,
            SlugGenerator::MAX_LENGTH_POST,
        );

        self::assertSame(str_repeat('a', SlugGenerator::MAX_LENGTH_POST - 2).'-2', $slug);
        self::assertLessThanOrEqual(SlugGenerator::MAX_LENGTH_POST, strlen($slug));
    }

    public function testNormalizeThrowsOnEmptyResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->normalize('!!!');
    }
}
