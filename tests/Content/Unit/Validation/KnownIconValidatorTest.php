<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Validation;

use App\Content\Domain\Validation\Constraints\KnownIcon;
use App\Content\Domain\Validation\Constraints\KnownIconValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\IconRendererInterface;

/**
 * @extends ConstraintValidatorTestCase<KnownIconValidator>
 */
final class KnownIconValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): KnownIconValidator
    {
        $renderer = $this->createStub(IconRendererInterface::class);
        $renderer->method('renderIcon')->willReturnCallback(static function (string $name): string {
            if ('tabler:file-import' !== $name) {
                throw new IconNotFoundException(\sprintf('Icon "%s" not found.', $name));
            }

            return '<svg></svg>';
        });

        return new KnownIconValidator($renderer);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function emptyValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    #[DataProvider('emptyValues')]
    public function testEmptyValuesAreValid(?string $value): void
    {
        $this->validator->validate($value, new KnownIcon());

        $this->assertNoViolation();
    }

    public function testKnownIconIsValid(): void
    {
        $this->validator->validate('tabler:file-import', new KnownIcon());

        $this->assertNoViolation();
    }

    public function testNameWithoutPrefixIsRejected(): void
    {
        $constraint = new KnownIcon();
        $this->validator->validate('upload', $constraint);

        $this->buildViolation($constraint->formatMessage)->assertRaised();
    }

    public function testUnknownIconIsRejected(): void
    {
        $constraint = new KnownIcon();
        $this->validator->validate('tabler:does-not-exist', $constraint);

        $this->buildViolation($constraint->unknownMessage)->setParameter('{icon}', 'tabler:does-not-exist')->assertRaised();
    }

    public function testOtherConstraintsAreRejected(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('tabler:file-import', new NotBlank());
    }

    public function testNonStringValuesAreRejected(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(42, new KnownIcon());
    }
}
