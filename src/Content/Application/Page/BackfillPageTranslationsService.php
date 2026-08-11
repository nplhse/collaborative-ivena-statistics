<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Repository\PageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BackfillPageTranslationsService
{
    public function __construct(
        private PageRepository $pageRepository,
        private EntityManagerInterface $entityManager,
        #[Autowire('%app.content.default_locale%')]
        private string $contentDefaultLocale,
    ) {
    }

    public function backfill(bool $dryRun): BackfillPageTranslationsResult
    {
        $processed = 0;
        $created = 0;
        $skipped = 0;
        $errors = 0;
        /** @var list<string> $errorMessages */
        $errorMessages = [];

        foreach ($this->pageRepository->findAllOrderedById() as $page) {
            ++$processed;
            $pageId = $page->getId() ?? 0;

            try {
                if ($page->hasTranslation($this->contentDefaultLocale)) {
                    ++$skipped;
                    continue;
                }

                $title = trim((string) $page->getTitle());
                $slug = trim((string) $page->getSlug());
                $path = trim((string) $page->getPath());
                if (in_array('', [$title, $slug, $path], true)) {
                    ++$errors;
                    $errorMessages[] = sprintf(
                        'Page #%d is missing legacy title/slug/path required for backfill.',
                        $pageId,
                    );
                    continue;
                }

                if ($dryRun) {
                    ++$created;
                    continue;
                }

                $translation = new PageTranslation();
                $translation->setLocale($this->contentDefaultLocale);
                $translation->setTitle($title);
                $translation->setSlug($slug);
                $translation->setPath($path);
                $translation->setStatus($page->getStatus());
                $translation->setContent($page->getContent());
                $page->addTranslation($translation);
                $this->entityManager->persist($translation);
                ++$created;
            } catch (\Throwable $e) {
                ++$errors;
                $errorMessages[] = sprintf('Page #%d: %s', $pageId, $e->getMessage());
            }
        }

        if (!$dryRun && $created > 0) {
            $this->entityManager->flush();
        }

        return new BackfillPageTranslationsResult(
            locale: $this->contentDefaultLocale,
            processed: $processed,
            created: $created,
            skipped: $skipped,
            errors: $errors,
            errorMessages: $errorMessages,
        );
    }
}
