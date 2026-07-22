<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Application\DTO\MciCaseRowDTO;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Indication\IndicationCache;
use App\Import\Infrastructure\Indication\IndicationKey;
use App\Import\Infrastructure\Resolver\Strategy\IndicationCreationStrategy;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IndicationCreationStrategyTest extends TestCase
{
    public function testSkipsSecondaryPathForMciCase(): void
    {
        $code = '123';
        $text = 'Brustschmerz';
        $hash = IndicationKey::hashFrom($code, $text);

        $repo = $this->createStub(IndicationRawRepository::class);
        $repo->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash, 'id' => 10, 'normalized_id' => 5],
            ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $rawRef = new IndicationRaw();
        $this->setId($rawRef, 10);
        $normRef = new IndicationNormalized();
        $this->setId($normRef, 5);

        $em->expects(self::never())->method('persist');
        $em->expects(self::exactly(2))
            ->method('getReference')
            ->willReturnCallback(
                fn (string $class, int $id): IndicationRaw|IndicationNormalized => match (true) {
                    IndicationRaw::class === $class && 10 === $id => $rawRef,
                    IndicationNormalized::class === $class && 5 === $id => $normRef,
                    default => $this->fail("Unexpected getReference: {$class}#{$id}"),
                }
            );

        $strategy = new IndicationCreationStrategy($repo, new IndicationCache(), $em);
        $strategy->warm();

        $dto = new MciCaseRowDTO();
        $dto->indicationCode = (int) $code;
        $dto->indication = $text;

        $mciCase = new MciCase();
        $strategy->apply($mciCase, $dto);

        self::assertSame($rawRef, $mciCase->getIndicationRaw());
        self::assertSame($normRef, $mciCase->getIndicationNormalized());
    }

    public function testSkipsSecondaryWhenOnlySecondaryCodeProvided(): void
    {
        $strategy = $this->createStrategyWithPrimaryInCacheOnly();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = 123;
        $dto->indication = 'Brustschmerz';
        $dto->secondaryIndicationCode = 456;
        $dto->secondaryIndication = null;

        $allocation = new Allocation();
        $strategy->apply($allocation, $dto);

        self::assertNull($allocation->getSecondaryIndicationRaw());
    }

    public function testSkipsSecondaryWhenSecondaryTextIsWhitespaceOnly(): void
    {
        $strategy = $this->createStrategyWithPrimaryInCacheOnly();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = 123;
        $dto->indication = 'Brustschmerz';
        $dto->secondaryIndicationCode = 456;
        $dto->secondaryIndication = '   ';

        $allocation = new Allocation();
        $strategy->apply($allocation, $dto);

        self::assertNull($allocation->getSecondaryIndicationRaw());
    }

    public function testPersistsSecondaryIndicationWhenNotInCache(): void
    {
        $hash1 = IndicationKey::hashFrom('123', 'Brustschmerz');
        IndicationKey::hashFrom('456', 'Covid');

        $repo = $this->createStub(IndicationRawRepository::class);
        $repo->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash1, 'id' => 10, 'normalized_id' => 5],
            ]);

        $rawRef1 = new IndicationRaw();
        $this->setId($rawRef1, 10);
        $normRef1 = new IndicationNormalized();
        $this->setId($normRef1, 5);

        $userRef = $this->createStub(User::class);

        /** @var EntityManagerInterface|MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('persist')
            ->with(self::callback(static fn ($arg): bool => $arg instanceof IndicationRaw && 456 === $arg->getCode() && 'Covid' === $arg->getName()));

        $em->expects(self::exactly(3))
            ->method('getReference')
            ->willReturnCallback(
                function (string $class, int $id) use ($rawRef1, $normRef1, $userRef): IndicationRaw|IndicationNormalized|User {
                    if (User::class === $class && 99 === $id) {
                        return $userRef;
                    }
                    if (IndicationRaw::class === $class && 10 === $id) {
                        return $rawRef1;
                    }
                    if (IndicationNormalized::class === $class && 5 === $id) {
                        return $normRef1;
                    }
                    $this->fail("Unexpected getReference: {$class}#{$id}");
                }
            );

        $createdBy = $this->createStub(User::class);
        $createdBy->method('getId')->willReturn(99);
        $import = $this->createStub(Import::class);
        $import->method('getCreatedBy')->willReturn($createdBy);

        $strategy = new IndicationCreationStrategy($repo, new IndicationCache(), $em);
        $strategy->warm();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = 123;
        $dto->indication = 'Brustschmerz';
        $dto->secondaryIndicationCode = 456;
        $dto->secondaryIndication = 'Covid';

        $allocation = new Allocation();
        $allocation->setImport($import);
        $strategy->apply($allocation, $dto);

        self::assertSame($rawRef1, $allocation->getIndicationRaw());
        $secondaryRaw = $allocation->getSecondaryIndicationRaw();
        self::assertNotNull($secondaryRaw);
        self::assertSame('Covid', $secondaryRaw->getName());
        self::assertSame(456, $secondaryRaw->getCode());
    }

    public function testDoesNotPersistSecondaryWhenImportHasNoCreatedBy(): void
    {
        $hash1 = IndicationKey::hashFrom('123', 'Brustschmerz');

        $repo = $this->createStub(IndicationRawRepository::class);
        $repo->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash1, 'id' => 10, 'normalized_id' => null],
            ]);

        $rawRef1 = new IndicationRaw();
        $this->setId($rawRef1, 10);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::once())
            ->method('getReference')
            ->willReturnCallback(
                fn (string $class, int $id): IndicationRaw => IndicationRaw::class === $class && 10 === $id
                    ? $rawRef1
                    : $this->fail("Unexpected getReference: {$class}#{$id}")
            );

        $import = $this->createStub(Import::class);
        $import->method('getCreatedBy')->willReturn(null);

        $strategy = new IndicationCreationStrategy($repo, new IndicationCache(), $em);
        $strategy->warm();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = 123;
        $dto->indication = 'Brustschmerz';
        $dto->secondaryIndicationCode = 456;
        $dto->secondaryIndication = 'Covid';

        $allocation = new Allocation();
        $allocation->setImport($import);
        $strategy->apply($allocation, $dto);

        self::assertNull($allocation->getSecondaryIndicationRaw());
    }

    public function testSkipsLoadingSecondaryNormalizedWhenAlreadySet(): void
    {
        $hash1 = IndicationKey::hashFrom('123', 'Brustschmerz');
        $hash2 = IndicationKey::hashFrom('456', 'Covid');

        $repo = $this->createStub(IndicationRawRepository::class);
        $repo->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash1, 'id' => 10, 'normalized_id' => 5],
                ['hash' => $hash2, 'id' => 11, 'normalized_id' => 6],
            ]);

        $rawRef1 = new IndicationRaw();
        $this->setId($rawRef1, 10);
        $normRef1 = new IndicationNormalized();
        $this->setId($normRef1, 5);
        $rawRef2 = new IndicationRaw();
        $this->setId($rawRef2, 11);
        $normRef2Pre = new IndicationNormalized();
        $this->setId($normRef2Pre, 99);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::exactly(3))
            ->method('getReference')
            ->willReturnCallback(
                fn (string $class, int $id): IndicationRaw|IndicationNormalized => match (true) {
                    IndicationRaw::class === $class && 10 === $id => $rawRef1,
                    IndicationNormalized::class === $class && 5 === $id => $normRef1,
                    IndicationRaw::class === $class && 11 === $id => $rawRef2,
                    default => $this->fail("Unexpected getReference: {$class}#{$id} — secondary norm should not load"),
                }
            );

        $strategy = new IndicationCreationStrategy($repo, new IndicationCache(), $em);
        $strategy->warm();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = 123;
        $dto->indication = 'Brustschmerz';
        $dto->secondaryIndicationCode = 456;
        $dto->secondaryIndication = 'Covid';

        $allocation = new Allocation();
        $allocation->setSecondaryIndicationNormalized($normRef2Pre);
        $strategy->apply($allocation, $dto);

        self::assertSame($rawRef2, $allocation->getSecondaryIndicationRaw());
        self::assertSame($normRef2Pre, $allocation->getSecondaryIndicationNormalized());
    }

    public function testSetsSecondaryNormalizedFromCacheWhenPresent(): void
    {
        $hash1 = IndicationKey::hashFrom('123', 'Brustschmerz');
        $hash2 = IndicationKey::hashFrom('456', 'Covid');

        $repo = $this->createStub(IndicationRawRepository::class);
        $repo->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash1, 'id' => 10, 'normalized_id' => 5],
                ['hash' => $hash2, 'id' => 11, 'normalized_id' => 6],
            ]);

        $rawRef1 = new IndicationRaw();
        $this->setId($rawRef1, 10);
        $normRef1 = new IndicationNormalized();
        $this->setId($normRef1, 5);
        $rawRef2 = new IndicationRaw();
        $this->setId($rawRef2, 11);
        $normRef2 = new IndicationNormalized();
        $this->setId($normRef2, 6);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::exactly(4))
            ->method('getReference')
            ->willReturnCallback(
                fn (string $class, int $id): IndicationRaw|IndicationNormalized => match (true) {
                    IndicationRaw::class === $class && 10 === $id => $rawRef1,
                    IndicationNormalized::class === $class && 5 === $id => $normRef1,
                    IndicationRaw::class === $class && 11 === $id => $rawRef2,
                    IndicationNormalized::class === $class && 6 === $id => $normRef2,
                    default => $this->fail("Unexpected getReference: {$class}#{$id}"),
                }
            );

        $strategy = new IndicationCreationStrategy($repo, new IndicationCache(), $em);
        $strategy->warm();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = 123;
        $dto->indication = 'Brustschmerz';
        $dto->secondaryIndicationCode = 456;
        $dto->secondaryIndication = 'Covid';

        $allocation = new Allocation();
        $strategy->apply($allocation, $dto);

        self::assertSame($normRef2, $allocation->getSecondaryIndicationNormalized());
    }

    public function testSkipsPrimaryNormalizedFromCacheWhenAlreadySetOnEntity(): void
    {
        $hash = IndicationKey::hashFrom('123', 'Brustschmerz');

        $repo = $this->createStub(IndicationRawRepository::class);
        $repo->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash, 'id' => 10, 'normalized_id' => 5],
            ]);

        $rawRef = new IndicationRaw();
        $this->setId($rawRef, 10);
        $preNorm = new IndicationNormalized();
        $this->setId($preNorm, 99);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::once())
            ->method('getReference')
            ->willReturnCallback(
                fn (string $class, int $id): IndicationRaw => match (true) {
                    IndicationRaw::class === $class && 10 === $id => $rawRef,
                    default => $this->fail("Unexpected getReference: {$class}#{$id} — primary norm from cache should be skipped"),
                }
            );

        $strategy = new IndicationCreationStrategy($repo, new IndicationCache(), $em);
        $strategy->warm();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = 123;
        $dto->indication = 'Brustschmerz';

        $allocation = new Allocation();
        $allocation->setIndicationNormalized($preNorm);
        $strategy->apply($allocation, $dto);

        self::assertSame($rawRef, $allocation->getIndicationRaw());
        self::assertSame($preNorm, $allocation->getIndicationNormalized());
    }

    private function createStrategyWithPrimaryInCacheOnly(): IndicationCreationStrategy
    {
        $hash1 = IndicationKey::hashFrom('123', 'Brustschmerz');

        $repo = $this->createStub(IndicationRawRepository::class);
        $repo->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash1, 'id' => 10, 'normalized_id' => 5],
            ]);

        $rawRef1 = new IndicationRaw();
        $this->setId($rawRef1, 10);
        $normRef1 = new IndicationNormalized();
        $this->setId($normRef1, 5);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::exactly(2))
            ->method('getReference')
            ->willReturnCallback(
                fn (string $class, int $id): IndicationRaw|IndicationNormalized => match (true) {
                    IndicationRaw::class === $class && 10 === $id => $rawRef1,
                    IndicationNormalized::class === $class && 5 === $id => $normRef1,
                    default => $this->fail("Unexpected getReference: {$class}#{$id}"),
                }
            );

        $strategy = new IndicationCreationStrategy($repo, new IndicationCache(), $em);
        $strategy->warm();

        return $strategy;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionObject($entity);

        do {
            if ($ref->hasProperty('id')) {
                $prop = $ref->getProperty('id');
                $prop->setValue($entity, $id);

                return;
            }
            $ref = $ref->getParentClass();
        } while ($ref);

        self::fail('Entity has no id property.');
    }
}
