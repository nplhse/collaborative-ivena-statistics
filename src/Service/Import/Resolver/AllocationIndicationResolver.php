<?php

namespace App\Service\Import\Resolver;

use App\Entity\Allocation;
use App\Entity\IndicationRaw;
use App\Repository\IndicationRawRepository;
use App\Service\Import\Contracts\AllocationEntityResolverInterface;
use App\Service\Import\DTO\AllocationRowDTO;
use App\Service\Import\Indication\IndicationCache;
use App\Service\Import\Indication\IndicationKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationIndicationResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private readonly IndicationRawRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly IndicationCache $cache,
    ) {
    }

    public function warm(): void
    {
        foreach ($this->repo->preloadAllLight() as $row) {
            $this->cache->addExisting(
                $row['hash'],
                (int) $row['id'],
                null !== $row['normalized_id'] ? (int) $row['normalized_id'] : null
            );
        }
    }

    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return true;
    }

    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        $hash = IndicationKey::hashFrom($dto->indicationCode, $dto->indication);

        if (!$this->cache->has($hash)) {
            $raw = new IndicationRaw()
                ->setCode(IndicationKey::normalizeCode($dto->indicationCode))
                ->setName(IndicationKey::normalizeText($dto->indication))
                ->setHash($hash)
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($raw);
            $this->cache->putNew($hash, $raw);
        }

        $rawRef = $this->cache->getRawRef($this->em, $hash);
        $entity->setIndicationRaw($rawRef);

        if (null === $entity->getIndicationNormalized()) {
            $normRef = $this->cache->getNormalizedRefOrNull($this->em, $hash);

            if (null !== $normRef) {
                $entity->setIndicationNormalized($normRef);
            }
        }
    }
}
