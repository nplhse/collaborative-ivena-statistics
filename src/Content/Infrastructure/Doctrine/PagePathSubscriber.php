<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
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
        $metadata = $em->getClassMetadata(PageTranslation::class);
        /** @var array<int, PageTranslation> $translationsToUpdate */
        $translationsToUpdate = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PageTranslation) {
                $translationsToUpdate[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof PageTranslation) {
                $translationsToUpdate[spl_object_id($entity)] = $entity;

                $changeSet = $uow->getEntityChangeSet($entity);
                if (isset($changeSet['slug']) || isset($changeSet['locale'])) {
                    $this->scheduleSameLocaleDescendants($entity, $translationsToUpdate);
                }
            }

            if (!$entity instanceof Page) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['parent'])) {
                continue;
            }

            foreach ($entity->getTranslations() as $translation) {
                $translationsToUpdate[spl_object_id($translation)] = $translation;
                $this->scheduleSameLocaleDescendants($translation, $translationsToUpdate);
            }
        }

        foreach ($translationsToUpdate as $translation) {
            try {
                $this->pathResolver->synchronize($translation);
            } catch (\InvalidArgumentException) {
                // Parent translation missing for this locale: leave path as-is; validation rejects publish.
                continue;
            }
            $uow->recomputeSingleEntityChangeSet($metadata, $translation);
        }
    }

    /**
     * @param array<int, PageTranslation> $translationsToUpdate
     */
    private function scheduleSameLocaleDescendants(PageTranslation $translation, array &$translationsToUpdate): void
    {
        $page = $translation->getPage();
        $locale = $translation->getLocale();
        if (!$page instanceof Page || null === $locale || '' === $locale) {
            return;
        }

        foreach ($this->collectDescendantPages($page) as $descendant) {
            $descendantTranslation = $descendant->translation($locale);
            if ($descendantTranslation instanceof PageTranslation) {
                $translationsToUpdate[spl_object_id($descendantTranslation)] = $descendantTranslation;
            }
        }
    }

    /**
     * @return list<Page>
     */
    private function collectDescendantPages(Page $page): array
    {
        $descendants = [];

        foreach ($page->getChildren() as $child) {
            $descendants[] = $child;

            foreach ($this->collectDescendantPages($child) as $grandChild) {
                $descendants[] = $grandChild;
            }
        }

        return $descendants;
    }
}
