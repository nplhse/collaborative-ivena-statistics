<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Repository\PageRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PageImageContentMigrationService
{
    public function __construct(
        private PageRepository $pageRepository,
        private PageImageContentAnalyzer $analyzer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function migrateSizes(bool $dryRun, ?int $pageId = null): PageImageContentMigrationResult
    {
        $findings = $this->analyzer->analyze($pageId);
        $candidatePageIds = [];

        foreach ($findings as $finding) {
            if ('Migrate size lg to auto' !== $finding->recommendation || 'image' !== $finding->blockType) {
                continue;
            }

            $candidatePageIds[$finding->pageId] = true;
        }

        $updatedBlocks = 0;

        foreach (array_keys($candidatePageIds) as $candidatePageId) {
            $page = $this->pageRepository->find($candidatePageId);
            if (!$page instanceof Page) {
                continue;
            }

            $content = $page->getContent();
            $changed = false;

            foreach ($content as $index => $block) {
                if ('image' !== $block['type']) {
                    continue;
                }

                $data = $block['data'];
                $size = (string) ($data['size'] ?? 'auto');
                $float = (string) ($data['float'] ?? 'none');

                if ('lg' !== $size || 'none' !== $float) {
                    continue;
                }

                $data['size'] = 'auto';
                $block['data'] = $data;
                $content[$index] = $block;
                $changed = true;
                ++$updatedBlocks;
            }

            if ($changed && !$dryRun) {
                $page->setContent($content);
            }
        }

        if (!$dryRun && $updatedBlocks > 0) {
            $this->entityManager->flush();
        }

        return new PageImageContentMigrationResult($updatedBlocks, count($candidatePageIds));
    }

    public function fixRichtextSnippets(bool $dryRun, ?int $pageId = null): PageImageContentMigrationResult
    {
        $pages = null === $pageId
            ? $this->pageRepository->findAll()
            : array_filter([$this->pageRepository->find($pageId)]);

        $updatedBlocks = 0;
        $updatedPages = 0;

        foreach ($pages as $page) {
            if (!$page instanceof Page) {
                continue;
            }

            $content = $page->getContent();
            $changed = false;

            foreach ($content as $index => $block) {
                $type = $block['type'];
                $data = $block['data'];

                if ('accordion' === $type) {
                    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                    foreach ($items as $itemIndex => $item) {
                        if (!is_array($item) || !is_string($item['html'] ?? null)) {
                            continue;
                        }

                        $updatedHtml = $this->replaceSnippetSizeClass($item['html']);
                        if ($updatedHtml !== $item['html']) {
                            $items[$itemIndex]['html'] = $updatedHtml;
                            $changed = true;
                            ++$updatedBlocks;
                        }
                    }

                    if ($changed) {
                        $data['items'] = $items;
                        $block['data'] = $data;
                        $content[$index] = $block;
                    }

                    continue;
                }

                if (!in_array($type, ['richtext', 'highlight'], true) || !is_string($data['html'] ?? null)) {
                    continue;
                }

                $updatedHtml = $this->replaceSnippetSizeClass($data['html']);
                if ($updatedHtml === $data['html']) {
                    continue;
                }

                $data['html'] = $updatedHtml;
                $block['data'] = $data;
                $content[$index] = $block;
                $changed = true;
                ++$updatedBlocks;
            }

            if ($changed) {
                ++$updatedPages;
                if (!$dryRun) {
                    $page->setContent($content);
                }
            }
        }

        if (!$dryRun && $updatedBlocks > 0) {
            $this->entityManager->flush();
        }

        return new PageImageContentMigrationResult($updatedBlocks, $updatedPages);
    }

    private function replaceSnippetSizeClass(string $html): string
    {
        return str_replace('page-content-image--size-lg', 'page-content-image--size-auto', $html);
    }
}

final readonly class PageImageContentMigrationResult
{
    public function __construct(
        public int $updatedBlocks,
        public int $updatedPages,
    ) {
    }
}
