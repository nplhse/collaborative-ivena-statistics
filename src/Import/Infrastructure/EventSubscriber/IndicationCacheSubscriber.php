<?php

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Infrastructure\Indication\IndicationCache;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final readonly class IndicationCacheSubscriber
{
    public function __construct(
        private IndicationCache $cache,
    ) {
    }

    public function postFlush(): void
    {
        $this->cache->promoteNewlyPersisted();
    }

    public function onClear(): void
    {
        $this->cache->afterClear();
    }
}
