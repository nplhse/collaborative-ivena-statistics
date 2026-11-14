<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Content\Application\Page\PageContentBlockDataNormalizer;

/**
 * Turns the block fields the editor posts for an autosave (`PageTranslation[content]`) into draft content.
 *
 * A draft keeps incomplete input (ADR 036): blocks are put into the editor's order and normalised like saved content,
 * but not validated, resolved against the media library or sanitised. Those steps run on the explicit save.
 */
final readonly class PageContentDraftBuilder
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private PageContentBlockDataNormalizer $normalizer,
    ) {
    }

    /**
     * @param array<array-key, mixed> $submitted
     *
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>
     */
    public function build(array $submitted): array
    {
        $blocks = [];
        $positions = [];
        foreach ($submitted as $key => $block) {
            if (!is_array($block)) {
                continue;
            }

            $positions[$key] = $block[PageContentBlockOrder::POSITION_FIELD] ?? null;
            $blocks[$key] = [
                'type' => is_string($block['type'] ?? null) ? $block['type'] : '',
                // An unchecked checkbox is not posted at all.
                'enabled' => array_key_exists('enabled', $block),
                'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
            ];
        }

        return $this->normalizer->normalize(PageContentBlockOrder::sortBlocks($blocks, $positions));
    }
}
