<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ReferenceCatalog\Package;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\Validation\ValidReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\Validation\ValidReferenceCatalogPackageValidator;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ValidReferenceCatalogPackageValidator>
 */
final class ValidReferenceCatalogPackageValidatorTest extends ConstraintValidatorTestCase
{
    public function testNullIsLeftToOtherConstraints(): void
    {
        $this->validator->validate(null, new ValidReferenceCatalogPackage());

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

        $this->validator->validate('package', new ValidReferenceCatalogPackage());
    }

    #[\Override]
    protected function createValidator(): ValidReferenceCatalogPackageValidator
    {
        return new ValidReferenceCatalogPackageValidator(new ReferenceCatalogChecksum($this->createStub(NormalizerInterface::class)));
    }
}
