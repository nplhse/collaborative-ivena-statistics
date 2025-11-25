<?php

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Infrastructure\Indication\IndicationCache;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final readonly class IndicationCacheSubscriber
{
    public function __construct(
        private IndicationCache $cache,
    ) {
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->cache->promoteNewlyPersisted();
    }

    public function onClear(OnClearEventArgs $args): void
    {
        $this->cache->afterClear();
    }
}
