<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Curation;

use App\Encounter\Application\Curation\Package\CurationChecksum;
use App\Encounter\Application\Curation\Package\Validation\ValidCurationPackage;
use App\Encounter\Application\Curation\Package\Validation\ValidCurationPackageValidator;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ValidCurationPackageValidator>
 */
final class ValidCurationPackageValidatorTest extends ConstraintValidatorTestCase
{
    public function testNullIsLeftToOtherConstraints(): void
    {
        $this->validator->validate(null, new ValidCurationPackage());

        $this->assertNoViolation();
    }

    public function testRejectsAnotherConstraint(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(null, new NotNull());
    }

    public function testRejectsAValueThatIsNoPackage(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate('package', new ValidCurationPackage());
    }

    #[\Override]
    protected function createValidator(): ValidCurationPackageValidator
    {
        return new ValidCurationPackageValidator(new CurationChecksum($this->createStub(NormalizerInterface::class)));
    }
}
