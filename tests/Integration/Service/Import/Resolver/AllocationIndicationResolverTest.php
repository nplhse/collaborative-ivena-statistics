<?php

namespace App\Tests\Integration\Service\Import\Resolver;

use App\Entity\Allocation;
use App\Entity\IndicationNormalized;
use App\Entity\IndicationRaw;
use App\Repository\IndicationRawRepository;
use App\Service\Import\DTO\AllocationRowDTO;
use App\Service\Import\Indication\IndicationCache;
use App\Service\Import\Indication\IndicationKey;
use App\Service\Import\Resolver\AllocationIndicationResolver;
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
        self::setId($rawRef, $rawId);
        $normRef = new IndicationNormalized();
        self::setId($normRef, $normId);

        $em->expects(self::never())->method('persist');
        $em->expects(self::any())
            ->method('getReference')
            ->willReturnCallback(function (string $class, int $id) use ($rawId, $normId, $rawRef, $normRef) {
                if (IndicationRaw::class === $class && $id === $rawId) {
                    return $rawRef;
                }
                if (IndicationNormalized::class === $class && $id === $normId) {
                    return $normRef;
                }
                $this->fail("Unexpected call to getReference: {$class}#{$id}");
            });

        $cache = new IndicationCache();

        $resolver = new AllocationIndicationResolver(
            repo: $repo,
            em: $em,
            cache: $cache,
        );

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

    private static function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionObject($entity);

        do {
            if ($ref->hasProperty('id')) {
                $prop = $ref->getProperty('id');
                $prop->setAccessible(true);
                $prop->setValue($entity, $id);

                return;
            }
            $ref = $ref->getParentClass();
        } while ($ref);

        self::fail('Entity has no id property.');
    }
}
