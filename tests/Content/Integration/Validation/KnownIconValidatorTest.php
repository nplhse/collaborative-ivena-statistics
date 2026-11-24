<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Validation;

use App\Content\Domain\Validation\Constraints\KnownIcon;
use App\Content\Domain\Validation\Constraints\KnownIconValidator;
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
            if ('tabler:upload' !== $name) {
                throw new IconNotFoundException(\sprintf('Icon "%s" not found.', $name));
            }

            return '<svg></svg>';
        });

        return new KnownIconValidator($renderer);
    }

    public function testEmptyValuesAreValid(): void
    {
        $this->validator->validate(null, new KnownIcon());
        $this->validator->validate('', new KnownIcon());

        $this->assertNoViolation();
    }

    public function testKnownIconIsValid(): void
    {
        $this->validator->validate('tabler:upload', new KnownIcon());

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
}
