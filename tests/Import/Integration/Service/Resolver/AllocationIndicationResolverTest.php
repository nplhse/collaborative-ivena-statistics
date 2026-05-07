<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Indication\IndicationCache;
use App\Import\Infrastructure\Indication\IndicationKey;
use App\Import\Infrastructure\Resolver\AllocationIndicationResolver;
use App\Import\Infrastructure\Resolver\Strategy\IndicationCreationStrategy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AllocationIndicationResolverTest extends TestCase
{
    public function testHappyPathUsesPreloadedCacheAndSetsBothRelations(): void
    {
        // Arrange
        $code = '123';
        $text = 'Brustschmerz';
        $hash = IndicationKey::hashFrom($code, $text);

        $rawId = 10;
        $normId = 5;

        /** @var IndicationRawRepository|MockObject $repo */
        $repo = $this->createMock(IndicationRawRepository::class);
        $repo->expects(self::once())
            ->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash, 'id' => $rawId, 'normalized_id' => $normId],
            ]);

        /** @var EntityManagerInterface|MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);

        $rawRef = new IndicationRaw();
        $this->setId($rawRef, $rawId);
        $normRef = new IndicationNormalized();
        $this->setId($normRef, $normId);

        $em->expects($this->never())->method('persist');
        $em->expects($this->any())
            ->method('getReference')
            ->willReturnCallback(function (string $class, int $id) use ($rawId, $normId, $rawRef, $normRef): IndicationRaw|\App\Allocation\Domain\Entity\IndicationNormalized {
                if (IndicationRaw::class === $class && $id === $rawId) {
                    return $rawRef;
                }
                if (IndicationNormalized::class === $class && $id === $normId) {
                    return $normRef;
                }
                $this->fail("Unexpected call to getReference: {$class}#{$id}");
            });

        $cache = new IndicationCache();

        $strategy = new IndicationCreationStrategy($repo, $cache, $em);
        $resolver = new AllocationIndicationResolver($strategy);

        $resolver->warm();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = (int) $code;
        $dto->indication = $text;

        $allocation = new Allocation();

        // Act
        $resolver->apply($allocation, $dto);

        // Assert
        self::assertSame($rawRef, $allocation->getIndicationRaw(), 'Normalized indication must be set via cache/reference.');
        self::assertSame($normRef, $allocation->getIndicationNormalized(), 'Normalized indication has to be set.');

        self::assertTrue($cache->has($hash), 'Cache should know hash.');
    }

    public function testSetsSecondaryIndicationFromCacheWhenDtoProvidesBoth(): void
    {
        $code1 = '123';
        $text1 = 'Brustschmerz';
        $hash1 = IndicationKey::hashFrom($code1, $text1);

        $code2 = '456';
        $text2 = 'Covid';
        $hash2 = IndicationKey::hashFrom($code2, $text2);

        $rawId1 = 10;
        $normId1 = 5;
        $rawId2 = 11;
        $normId2 = 6;

        /** @var IndicationRawRepository|MockObject $repo */
        $repo = $this->createMock(IndicationRawRepository::class);
        $repo->expects(self::once())
            ->method('preloadAllLight')
            ->willReturn([
                ['hash' => $hash1, 'id' => $rawId1, 'normalized_id' => $normId1],
                ['hash' => $hash2, 'id' => $rawId2, 'normalized_id' => $normId2],
            ]);

        /** @var EntityManagerInterface|MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);

        $rawRef1 = new IndicationRaw();
        $this->setId($rawRef1, $rawId1);
        $normRef1 = new IndicationNormalized();
        $this->setId($normRef1, $normId1);
        $rawRef2 = new IndicationRaw();
        $this->setId($rawRef2, $rawId2);
        $normRef2 = new IndicationNormalized();
        $this->setId($normRef2, $normId2);

        $em->expects($this->never())->method('persist');
        $em->expects($this->any())
            ->method('getReference')
            ->willReturnCallback(fn (string $class, int $id): IndicationRaw|IndicationNormalized => match (true) {
                IndicationRaw::class === $class && $id === $rawId1 => $rawRef1,
                IndicationNormalized::class === $class && $id === $normId1 => $normRef1,
                IndicationRaw::class === $class && $id === $rawId2 => $rawRef2,
                IndicationNormalized::class === $class && $id === $normId2 => $normRef2,
                default => $this->fail("Unexpected call to getReference: {$class}#{$id}"),
            });

        $cache = new IndicationCache();
        $strategy = new IndicationCreationStrategy($repo, $cache, $em);
        $resolver = new AllocationIndicationResolver($strategy);
        $resolver->warm();

        $dto = new AllocationRowDTO();
        $dto->indicationCode = (int) $code1;
        $dto->indication = $text1;
        $dto->secondaryIndicationCode = (int) $code2;
        $dto->secondaryIndication = $text2;

        $allocation = new Allocation();
        $resolver->apply($allocation, $dto);

        self::assertSame($rawRef1, $allocation->getIndicationRaw());
        self::assertSame($normRef1, $allocation->getIndicationNormalized());
        self::assertSame($rawRef2, $allocation->getSecondaryIndicationRaw());
        self::assertSame($normRef2, $allocation->getSecondaryIndicationNormalized());
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
