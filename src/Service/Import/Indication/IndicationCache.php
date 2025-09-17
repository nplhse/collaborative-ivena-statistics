<?php

namespace App\Service\Import\Indication;

use App\Entity\IndicationNormalized;
use App\Entity\IndicationRaw;
use Doctrine\ORM\EntityManagerInterface;

final class IndicationCache
{
    /** @var array<string,int> */
    private array $rawIdByHash = [];
    /** @var array<string,int|null> */
    private array $normIdByHash = [];
    /** @var array<string,IndicationRaw> */
    private array $newByHash = [];

    public function addExisting(string $hash, int $rawId, ?int $normId): void
    {
        $this->rawIdByHash[$hash] = $rawId;
        $this->normIdByHash[$hash] = $normId;
    }

    public function has(string $hash): bool
    {
        return isset($this->rawIdByHash[$hash]) || isset($this->newByHash[$hash]);
    }

    public function putNew(string $hash, IndicationRaw $raw): void
    {
        $this->newByHash[$hash] = $raw;
        $this->normIdByHash[$hash] = null;
    }

    public function getRawRef(EntityManagerInterface $em, string $hash): IndicationRaw
    {
        if (isset($this->newByHash[$hash])) {
            return $this->newByHash[$hash];
        }
        /** @var IndicationRaw $ref */
        $ref = $em->getReference(IndicationRaw::class, $this->rawIdByHash[$hash]);

        return $ref;
    }

    public function getNormalizedRefOrNull(EntityManagerInterface $em, string $hash): ?IndicationNormalized
    {
        $id = $this->normIdByHash[$hash] ?? null;

        if (null === $id) {
            return null;
        }

        return $em->getReference(IndicationNormalized::class, $id);
    }

    public function promoteNewlyPersisted(): void
    {
        foreach ($this->newByHash as $hash => $raw) {
            $id = $raw->getId();

            if (null !== $id) {
                $this->rawIdByHash[$hash] = $id;
                unset($this->newByHash[$hash]);
            }
        }
    }

    public function afterClear(): void
    {
        $this->newByHash = [];
    }

    public function updateNormalizedId(string $hash, ?int $normId): void
    {
        $this->normIdByHash[$hash] = $normId;
    }
}
