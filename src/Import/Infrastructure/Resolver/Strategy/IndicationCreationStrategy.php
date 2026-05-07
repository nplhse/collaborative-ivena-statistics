<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Indication\IndicationCache;
use App\Import\Infrastructure\Indication\IndicationKey;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IndicationCreationStrategy
{
    public function __construct(
        private IndicationRawRepository $indicationRawRepository,
        private IndicationCache $indicationCache,
        private EntityManagerInterface $em,
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
     * @param object $entity must expose getImport(), setIndicationRaw(), getIndicationNormalized(), setIndicationNormalized()
     * @param object $dto    must expose indicationCode, indication; Allocation imports may also expose secondaryIndicationCode, secondaryIndication
     */
    public function apply(object $entity, object $dto): void
    {
        if (null === $dto->indicationCode || null === $dto->indication) {
            return;
        }

        $hash = IndicationKey::hashFrom((string) $dto->indicationCode, $dto->indication);

        if (!$this->indicationCache->has($hash)) {
            $raw = new IndicationRaw()
                ->setCode($dto->indicationCode)
                ->setName($dto->indication)
                ->setHash($hash)
                ->setCreatedAt(new \DateTimeImmutable());

            $import = $entity->getImport();
            $createdById = $import?->getCreatedBy()?->getId();
            if (null === $createdById) {
                return;
            }

            /** @var \App\User\Domain\Entity\User $createdByRef */
            $createdByRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $createdById);
            $raw->setCreatedBy($createdByRef);

            $this->em->persist($raw);
            $this->indicationCache->putNew($hash, $raw);
        }

        $rawRef = $this->indicationCache->getRawRef($this->em, $hash);
        $entity->setIndicationRaw($rawRef);

        if (null !== $entity->getIndicationNormalized()) {
            // primary normalized already set — skip auto from cache
        } else {
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

        $hash = IndicationKey::hashFrom((string) $secCode, $secText);

        if (!$this->indicationCache->has($hash)) {
            $raw = new IndicationRaw()
                ->setCode($secCode)
                ->setName($secText)
                ->setHash($hash)
                ->setCreatedAt(new \DateTimeImmutable());

            $import = $entity->getImport();
            $createdById = $import?->getCreatedBy()?->getId();
            if (null === $createdById) {
                return;
            }

            /** @var \App\User\Domain\Entity\User $createdByRef */
            $createdByRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $createdById);
            $raw->setCreatedBy($createdByRef);

            $this->em->persist($raw);
            $this->indicationCache->putNew($hash, $raw);
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
}
