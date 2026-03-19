<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Import\Infrastructure\Indication\IndicationCache;
use App\Import\Infrastructure\Indication\IndicationKey;
use Doctrine\ORM\EntityManagerInterface;

final class IndicationCreationStrategy
{
    public function __construct(
        private readonly IndicationRawRepository $indicationRawRepository,
        private readonly IndicationCache $indicationCache,
        private readonly EntityManagerInterface $em,
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
     * @param object $dto    must expose indicationCode, indication
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
            return;
        }

        $normalizedRefOrNull = $this->indicationCache->getNormalizedRefOrNull($this->em, $hash);
        if (null !== $normalizedRefOrNull) {
            $entity->setIndicationNormalized($normalizedRefOrNull);
        }
    }
}
