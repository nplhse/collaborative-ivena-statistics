<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Page\DTO\PageImageContentFinding;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\MediaType;
use App\Content\Domain\ValueObject\ImageDimensions;
use App\Content\Infrastructure\Media\LocalMediaFileLocator;
use App\Content\Infrastructure\Media\MediaDimensionsExtractor;
use App\Content\Infrastructure\Repository\MediaRepository;
use App\Content\Infrastructure\Repository\PageRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PageImageContentAnalyzer
{
    /** @var list<string> */
    private const array HTML_BLOCK_TYPES = ['richtext', 'highlight', 'accordion'];

    public function __construct(
        private PageRepository $pageRepository,
        private MediaRepository $mediaRepository,
        private LocalMediaFileLocator $fileLocator,
        private MediaDimensionsExtractor $dimensionsExtractor,
        #[Autowire('%app.page_content_width_estimate%')]
        private int $contentWidthEstimate,
    ) {
    }

    /**
     * @return list<PageImageContentFinding>
     */
    public function analyze(?int $pageId = null): array
    {
        $pages = null === $pageId
            ? $this->pageRepository->findAll()
            : array_filter([$this->pageRepository->find($pageId)]);

        $findings = [];

        foreach ($pages as $page) {
            if (!$page instanceof Page) {
                continue;
            }

            foreach ($page->getContent() as $index => $block) {
                $type = $block['type'];
                $data = $block['data'];

                if ('image' === $type) {
                    $findings[] = $this->analyzeImageBlock($page, $index, $type, $data);
                    continue;
                }

                if (!in_array($type, self::HTML_BLOCK_TYPES, true)) {
                    continue;
                }

                if ('accordion' === $type) {
                    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                    foreach ($items as $itemIndex => $item) {
                        if (!is_array($item) || !is_string($item['html'] ?? null)) {
                            continue;
                        }

                        foreach ($this->analyzeHtmlImages($page, $index, $type.' item '.($itemIndex + 1), $item['html']) as $finding) {
                            $findings[] = $finding;
                        }
                    }
                    continue;
                }

                $html = $data['html'] ?? null;
                if (!is_string($html) || '' === $html) {
                    continue;
                }

                foreach ($this->analyzeHtmlImages($page, $index, $type, $html) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function analyzeImageBlock(Page $page, int $blockIndex, string $blockType, array $data): PageImageContentFinding
    {
        $size = $this->resolveSize($data);
        $float = (string) ($data['float'] ?? 'none');
        $mediaContext = $this->resolveMediaContext($data);

        return $this->buildFinding(
            $page,
            $blockIndex,
            $blockType,
            $size,
            $float,
            $mediaContext['mediaId'],
            $mediaContext['filename'],
            $mediaContext['imageWidth'],
            $mediaContext['imageHeight'],
            $mediaContext['status'],
        );
    }

    /**
     * @return list<PageImageContentFinding>
     */
    private function analyzeHtmlImages(Page $page, int $blockIndex, string $blockType, string $html): array
    {
        $findings = [];

        if (false !== preg_match_all('/page-content-image--size-([a-z]+)/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $matchIndex => $match) {
                $size = $match[1];
                $mediaContext = $this->resolveMediaContextFromHtml($html);
                $findings[] = $this->buildFinding(
                    $page,
                    $blockIndex,
                    $blockType.' snippet '.($matchIndex + 1),
                    $size,
                    $this->resolveFloatFromHtml($html),
                    $mediaContext['mediaId'],
                    $mediaContext['filename'],
                    $mediaContext['imageWidth'],
                    $mediaContext['imageHeight'],
                    $mediaContext['status'],
                );
            }
        }

        if (false !== preg_match_all('/<img[^>]+src="(\/uploads\/media\/[^"]+)"[^>]*>/i', $html, $imgMatches, PREG_SET_ORDER)) {
            foreach ($imgMatches as $matchIndex => $match) {
                if ($this->htmlContainsPageContentImageClass($html)) {
                    continue;
                }

                $src = $match[1];
                $mediaContext = $this->resolveMediaContext(['src' => $src]);
                $findings[] = $this->buildFinding(
                    $page,
                    $blockIndex,
                    $blockType.' inline img '.($matchIndex + 1),
                    'auto',
                    'none',
                    $mediaContext['mediaId'],
                    $mediaContext['filename'],
                    $mediaContext['imageWidth'],
                    $mediaContext['imageHeight'],
                    $mediaContext['status'],
                );
            }
        }

        return $findings;
    }

    private function htmlContainsPageContentImageClass(string $html): bool
    {
        return str_contains($html, 'page-content-image--size-');
    }

    private function resolveFloatFromHtml(string $html): string
    {
        if (str_contains($html, 'page-content-image--float-left')) {
            return 'left';
        }

        if (str_contains($html, 'page-content-image--float-right')) {
            return 'right';
        }

        return 'none';
    }

    /**
     * @return array{mediaId: ?int, filename: ?string, imageWidth: ?int, imageHeight: ?int, status: string}
     */
    private function resolveMediaContextFromHtml(string $html): array
    {
        if (preg_match('/src="(\/uploads\/media\/[^"]+)"/', $html, $match)) {
            return $this->resolveMediaContext(['src' => $match[1]]);
        }

        return [
            'mediaId' => null,
            'filename' => null,
            'imageWidth' => null,
            'imageHeight' => null,
            'status' => 'missing_src',
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{mediaId: ?int, filename: ?string, imageWidth: ?int, imageHeight: ?int, status: string}
     */
    private function resolveMediaContext(array $data): array
    {
        $media = $this->resolveMedia($data);
        if ($media instanceof Media) {
            $absolutePath = $this->fileLocator->resolveAbsolutePath($media);
            if (null === $absolutePath || !is_file($absolutePath)) {
                return [
                    'mediaId' => $media->getId(),
                    'filename' => $media->getFilename(),
                    'imageWidth' => $media->getWidth(),
                    'imageHeight' => $media->getHeight(),
                    'status' => 'missing_file',
                ];
            }

            $width = $media->getWidth();
            $height = $media->getHeight();
            if (null === $width || null === $height) {
                $dimensions = $this->dimensionsExtractor->extract($absolutePath);
                if ($dimensions instanceof ImageDimensions) {
                    $width = $dimensions->width;
                    $height = $dimensions->height;
                }
            }

            return [
                'mediaId' => $media->getId(),
                'filename' => $media->getFilename(),
                'imageWidth' => $width,
                'imageHeight' => $height,
                'status' => (null === $width || null === $height) ? 'dimensions_unknown' : 'ok',
            ];
        }

        $src = trim((string) ($data['src'] ?? ''));
        if ('' === $src) {
            return [
                'mediaId' => null,
                'filename' => null,
                'imageWidth' => null,
                'imageHeight' => null,
                'status' => 'missing_src',
            ];
        }

        $filename = $this->fileLocator->resolveFilenameFromPublicSrc($src);
        if (null === $filename) {
            return [
                'mediaId' => null,
                'filename' => null,
                'imageWidth' => null,
                'imageHeight' => null,
                'status' => 'external_src',
            ];
        }

        $absolutePath = $this->fileLocator->resolveAbsolutePathFromFilename($filename);
        if (!is_file($absolutePath)) {
            return [
                'mediaId' => null,
                'filename' => $filename,
                'imageWidth' => null,
                'imageHeight' => null,
                'status' => 'missing_file',
            ];
        }

        $dimensions = $this->dimensionsExtractor->extract($absolutePath);

        return [
            'mediaId' => null,
            'filename' => $filename,
            'imageWidth' => $dimensions?->width,
            'imageHeight' => $dimensions?->height,
            'status' => $dimensions instanceof ImageDimensions ? 'ok' : 'dimensions_unknown',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveMedia(array $data): ?Media
    {
        $mediaId = $data['mediaId'] ?? null;
        if (is_int($mediaId) && $mediaId > 0) {
            $media = $this->mediaRepository->findOneById($mediaId);

            return $media instanceof Media && MediaType::IMAGE === $media->getType() ? $media : null;
        }

        if (is_string($mediaId) && ctype_digit($mediaId)) {
            $media = $this->mediaRepository->findOneById((int) $mediaId);

            return $media instanceof Media && MediaType::IMAGE === $media->getType() ? $media : null;
        }

        $src = trim((string) ($data['src'] ?? ''));
        $filename = $this->fileLocator->resolveFilenameFromPublicSrc($src);
        if (null === $filename) {
            return null;
        }

        $media = $this->mediaRepository->findOneBy(['filename' => $filename, 'type' => MediaType::IMAGE]);

        return $media instanceof Media ? $media : null;
    }

    private function buildFinding(
        Page $page,
        int $blockIndex,
        string $blockType,
        string $size,
        string $float,
        ?int $mediaId,
        ?string $filename,
        ?int $imageWidth,
        ?int $imageHeight,
        string $status,
    ): PageImageContentFinding {
        return new PageImageContentFinding(
            pageId: (int) $page->getId(),
            pageTitle: $page->getTitle() ?? '',
            pageSlug: $page->getSlug() ?? '',
            blockIndex: $blockIndex,
            blockType: $blockType,
            mediaId: $mediaId,
            filename: $filename,
            imageWidth: $imageWidth,
            imageHeight: $imageHeight,
            currentSize: $size,
            float: $float,
            status: $status,
            recommendation: $this->buildRecommendation($size, $float, $imageWidth, $status),
        );
    }

    private function buildRecommendation(string $size, string $float, ?int $imageWidth, string $status): string
    {
        if ('missing_file' === $status) {
            return 'Sync media files locally';
        }

        if ('dimensions_unknown' === $status) {
            return 'Backfill image dimensions';
        }

        if ('lg' === $size && 'none' === $float && null !== $imageWidth && $imageWidth < $this->contentWidthEstimate) {
            return 'Migrate size lg to auto';
        }

        if ('lg' === $size && str_contains($status, 'richtext')) {
            return 'Replace --size-lg with --size-auto in HTML snippet';
        }

        if ('auto' === $size || in_array($size, ['sm', 'md'], true)) {
            return 'No change';
        }

        return 'Review layout';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveSize(array $data): string
    {
        $size = $data['size'] ?? null;
        if (is_string($size) && in_array($size, ['auto', 'sm', 'md', 'lg'], true)) {
            return $size;
        }

        $preset = (string) ($data['widthPreset'] ?? '');

        return match ($preset) {
            'sm' => 'sm',
            'md' => 'md',
            'lg' => 'lg',
            default => 'auto',
        };
    }
}
