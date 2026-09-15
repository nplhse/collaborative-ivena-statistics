<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\IndicationKey;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\ImportCreatedById;
use App\Import\Infrastructure\Indication\IndicationCache;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IndicationCreationStrategy
{
    public function __construct(
        private IndicationRawRepository $indicationRawRepository,
        private IndicationCache $indicationCache,
        private EntityManagerInterface $em,
        private ImportCreatedById $importCreatedById,
    ) {
    }

    public function warm(): void
    {
        foreach ($this->indicationRawRepository->preloadAllLight() as $row) {
            $this->indicationCache->addExisting(
                $row['hash'],
                $row['id'],
                $row['normalized_id'],
            );
        }
    }

    /**
     * @param object $entity must expose setIndicationRaw(), getIndicationNormalized(), setIndicationNormalized()
     * @param object $dto    must expose indicationCode, indication; Allocation imports may also expose secondaryIndicationCode, secondaryIndication
     */
    public function apply(object $entity, object $dto): void
    {
        if (null === $dto->indicationCode || null === $dto->indication) {
            return;
        }

        $hash = $this->ensureRawInCache((int) $dto->indicationCode, $dto->indication);
        if (null === $hash) {
            return;
        }

        $rawRef = $this->indicationCache->getRawRef($this->em, $hash);
        $entity->setIndicationRaw($rawRef);

        if (null === $entity->getIndicationNormalized()) {
            $normalizedRefOrNull = $this->indicationCache->getNormalizedRefOrNull($this->em, $hash);
            if ($normalizedRefOrNull instanceof IndicationNormalized) {
                $entity->setIndicationNormalized($normalizedRefOrNull);
            }
        }

        $this->applySecondaryIndicationForAllocation($entity, $dto);
    }

    private function applySecondaryIndicationForAllocation(object $entity, object $dto): void
    {
        if (!$entity instanceof Allocation || !$dto instanceof AllocationRowDTO) {
            return;
        }

        $secCode = $dto->secondaryIndicationCode;
        $secText = $dto->secondaryIndication;
        if (null === $secCode || null === $secText || '' === trim($secText)) {
            return;
        }

        $hash = $this->ensureRawInCache($secCode, $secText);
        if (null === $hash) {
            return;
        }

        $rawRef = $this->indicationCache->getRawRef($this->em, $hash);
        $entity->setSecondaryIndicationRaw($rawRef);

        if ($entity->getSecondaryIndicationNormalized() instanceof IndicationNormalized) {
            return;
        }

        $normalizedRefOrNull = $this->indicationCache->getNormalizedRefOrNull($this->em, $hash);
        if ($normalizedRefOrNull instanceof IndicationNormalized) {
            $entity->setSecondaryIndicationNormalized($normalizedRefOrNull);
        }
    }

    /**
     * @return string|null hash when a raw indication is available, null when createdBy is missing on a catalog miss
     */
    private function ensureRawInCache(int $code, string $name): ?string
    {
        $hash = IndicationKey::hashFrom((string) $code, $name);
        if ($this->indicationCache->has($hash)) {
            return $hash;
        }

        $createdById = $this->importCreatedById->userId();
        if (null === $createdById) {
            return null;
        }

        $raw = new IndicationRaw()
            ->setCode($code)
            ->setName($name)
            ->setHash($hash)
            ->setCreatedAt(new \DateTimeImmutable());

        /** @var User $createdByRef */
        $createdByRef = $this->em->getReference(User::class, $createdById);
        $raw->setCreatedBy($createdByRef);

        $this->em->persist($raw);
        $this->indicationCache->putNew($hash, $raw);

        return $hash;
    }
}
