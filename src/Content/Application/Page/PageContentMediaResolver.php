<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Infrastructure\Repository\MediaRepository;

final readonly class PageContentMediaResolver
{
    public function __construct(
        private MediaRepository $mediaRepository,
        private MediaPublicUrlResolver $publicUrlResolver,
    ) {
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>, enabled?: bool}> $content
     *
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>
     */
    public function resolve(array $content): array
    {
        $resolved = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? null;
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $data = $this->normalizeMediaReferences($data);

            if ('image' === $type) {
                $data = $this->resolveImageBlock($data);
            } elseif ('cta' === $type) {
                $data = $this->resolveCtaBlock($data);
            }

            $block['data'] = $data;
            $resolved[] = $block;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeMediaReferences(array $data): array
    {
        if (($data['mediaId'] ?? null) instanceof Media) {
            $data['mediaId'] = $data['mediaId']->getId();
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolveImageBlock(array $data): array
    {
        $mediaId = $this->extractMediaId($data['mediaId'] ?? null);
        if (null === $mediaId) {
            return $data;
        }

        $media = $this->requireMedia($mediaId, MediaType::IMAGE);
        $data['src'] = $this->publicUrlResolver->resolve($media);

        $alt = trim((string) ($data['alt'] ?? ''));
        if ('' === $alt) {
            $data['alt'] = $this->defaultAltFromMedia($media);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolveCtaBlock(array $data): array
    {
        $linkType = (string) ($data['linkType'] ?? 'url');
        if ('media' !== $linkType) {
            return $data;
        }

        $mediaId = $this->extractMediaId($data['mediaId'] ?? null);
        if (null === $mediaId) {
            throw new \InvalidArgumentException('CTA block with linkType "media" requires mediaId.');
        }

        $media = $this->requireMedia($mediaId, MediaType::PDF);
        $data['buttonUrl'] = $this->publicUrlResolver->resolve($media);
        $data['openInNewTab'] = true;

        return $data;
    }

    private function requireMedia(int $mediaId, MediaType $expectedType): Media
    {
        $media = $this->mediaRepository->findOneById($mediaId);
        if (!$media instanceof Media) {
            throw new \InvalidArgumentException(sprintf('Media with id %d not found.', $mediaId));
        }

        $actualType = $media->getType();
        if ($actualType !== $expectedType) {
            $actualTypeLabel = $actualType instanceof MediaType ? $actualType->value : 'unknown';
            throw new \InvalidArgumentException(sprintf('Media %d must be of type %s, got %s.', $mediaId, $expectedType->value, $actualTypeLabel));
        }

        if ('' === $this->publicUrlResolver->resolve($media)) {
            throw new \InvalidArgumentException(sprintf('Media %d has no uploaded file.', $mediaId));
        }

        return $media;
    }

    private function defaultAltFromMedia(Media $media): string
    {
        $alt = trim($media->getAltText() ?? '');
        if ('' !== $alt) {
            return $alt;
        }

        $title = trim($media->getTitle() ?? '');
        if ('' !== $title) {
            return $title;
        }

        return $media->getOriginalFilename() ?? '';
    }

    private function extractMediaId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && '' !== $value && ctype_digit($value)) {
            return (int) $value;
        }

        if ($value instanceof Media) {
            return $value->getId();
        }

        return null;
    }
}
