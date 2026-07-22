<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Contracts\RowToDtoMapperInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Application\Exception\ReferenceNotFoundException;
use App\Import\Application\Exception\RowRejectException;
use App\Import\Application\Service\AllocationRowProcessor;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\AllocationRowType;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AllocationRowProcessorTest extends TestCase
{
    /**
     * @param iterable<AllocationEntityResolverInterface> $resolvers
     */
    private function createFactory(EntityManagerInterface $em, iterable $resolvers = []): AllocationImportFactory
    {
        return new AllocationImportFactory($em, $resolvers);
    }

    private function createEmStub(): EntityManagerInterface
    {
        return $this->createStub(EntityManagerInterface::class);
    }

    public function testTypeReturnsAllocation(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $mapper = $this->createStub(RowToDtoMapperInterface::class);
        $mapper->method('mapAssoc')->willReturn(new AllocationRowDTO());
        $factory = $this->createFactory($this->createEmStub());
        $persister = $this->createStub(AllocationPersisterInterface::class);

        $processor = new AllocationRowProcessor($validator, $mapper, $factory, $persister);

        self::assertSame(AllocationRowType::ALLOCATION, $processor->type());
    }

    public function testWarmDelegatesToFactoryWithoutThrowing(): void
    {
        $warmed = new class implements AllocationEntityResolverInterface {
            public bool $warmed = false;

            #[\Override]
            public function warm(): void
            {
                $this->warmed = true;
            }

            #[\Override]
            public function supports(Allocation $entity, AllocationRowDTO $dto): bool
            {
                return false;
            }

            #[\Override]
            public function apply(Allocation $entity, AllocationRowDTO $dto): void
            {
            }
        };

        $validator = $this->createStub(ValidatorInterface::class);
        $mapper = $this->createStub(RowToDtoMapperInterface::class);
        $mapper->method('mapAssoc')->willReturn(new AllocationRowDTO());
        $factory = $this->createFactory($this->createEmStub(), [$warmed]);
        $persister = $this->createStub(AllocationPersisterInterface::class);

        $processor = new AllocationRowProcessor($validator, $mapper, $factory, $persister);

        $processor->warm();

        self::assertTrue($warmed->warmed);
    }

    public function testValidationFailureThrowsRowRejectExceptionAndNeverPersists(): void
    {
        $violation = new ConstraintViolation(
            message: 'Invalid age',
            messageTemplate: null,
            parameters: [],
            root: new AllocationRowDTO(),
            propertyPath: 'age',
            invalidValue: -1,
        );
        $violations = new ConstraintViolationList([$violation]);

        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn($violations);

        $mapper = $this->createStub(RowToDtoMapperInterface::class);
        $mapper->method('mapAssoc')->willReturn(new AllocationRowDTO());

        $factory = $this->createFactory($this->createEmStub());

        $persister = $this->createMock(AllocationPersisterInterface::class);
        $persister->expects($this->never())->method('persist');
        $persister->expects($this->never())->method('flush');

        $processor = new AllocationRowProcessor($validator, $mapper, $factory, $persister);

        try {
            $processor->process(['age' => '-1'], new Import(), 1);
            self::fail('Expected RowRejectException to be thrown.');
        } catch (RowRejectException $e) {
            self::assertSame(['age: Invalid age'], $e->messages());
        }
    }

    public function testImportExceptionFromFactoryIsMappedToRowRejectException(): void
    {
        $hospital = new Hospital();
        $import = new Import();
        $import->setHospital($hospital);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getReference')->willReturnCallback(
            static fn (string $class, mixed $id): object => match ($class) {
                Hospital::class => $hospital,
                Import::class => $import,
                default => throw new \LogicException("Unexpected reference class: {$class}"),
            },
        );

        $throwingResolver = new class implements AllocationEntityResolverInterface {
            #[\Override]
            public function warm(): void
            {
            }

            #[\Override]
            public function supports(Allocation $entity, AllocationRowDTO $dto): bool
            {
                return true;
            }

            #[\Override]
            public function apply(Allocation $entity, AllocationRowDTO $dto): void
            {
                throw ReferenceNotFoundException::forField('dispatchArea', 'XYZ');
            }
        };

        $factory = $this->createFactory($em, [$throwingResolver]);

        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $mapper = $this->createStub(RowToDtoMapperInterface::class);
        $mapper->method('mapAssoc')->willReturn(new AllocationRowDTO());

        $persister = $this->createMock(AllocationPersisterInterface::class);
        $persister->expects($this->never())->method('persist');
        $persister->expects($this->never())->method('flush');

        $processor = new AllocationRowProcessor($validator, $mapper, $factory, $persister);

        try {
            $processor->process(['dispatchArea' => 'XYZ'], $import, 1);
            self::fail('Expected RowRejectException to be thrown.');
        } catch (RowRejectException $e) {
            self::assertSame(
                ['REF_NOT_FOUND | Reference not found for "dispatchArea" | field=dispatchArea | value="XYZ"'],
                $e->messages(),
            );
            self::assertSame([
                'error_code' => 'REF_NOT_FOUND',
                'field' => 'dispatchArea',
                'value' => 'XYZ',
                'exception' => ReferenceNotFoundException::class,
                'message' => 'Reference not found for "dispatchArea"',
            ], $e->context());
        }
    }
}
