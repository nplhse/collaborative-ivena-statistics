<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/** @psalm-suppress UnusedClass */
#[AsDoctrineListener(event: Events::onFlush, priority: 300, connection: 'default')]
final readonly class PagePathSubscriber
{
    public function __construct(
        private PagePathResolver $pathResolver,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(Page::class);
        $pagesToUpdate = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Page) {
                $pagesToUpdate[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Page) {
                continue;
            }

            $pagesToUpdate[spl_object_id($entity)] = $entity;

            $changeSet = $uow->getEntityChangeSet($entity);
            if (isset($changeSet['parent']) || isset($changeSet['slug'])) {
                foreach ($this->collectDescendants($entity) as $descendant) {
                    $pagesToUpdate[spl_object_id($descendant)] = $descendant;
                }
            }
        }

        foreach ($pagesToUpdate as $page) {
            $page->setSlug($this->pathResolver->normalizeSlug($page->getSlug()));
            $page->setPath($this->pathResolver->buildPath($page));
            $uow->recomputeSingleEntityChangeSet($metadata, $page);
        }
    }

    /**
     * @return list<Page>
     */
    private function collectDescendants(Page $page): array
    {
        $descendants = [];

        foreach ($page->getChildren() as $child) {
            $descendants[] = $child;

            foreach ($this->collectDescendants($child) as $grandChild) {
                $descendants[] = $grandChild;
            }
        }

        return $descendants;
    }
}
