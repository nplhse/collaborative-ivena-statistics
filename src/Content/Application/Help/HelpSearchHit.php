<?php

declare(strict_types=1);

namespace App\Content\Application\Help;

/**
 * One help article that matches a search (#596), in the language version the viewer sees.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the help search template.
 */
final readonly class HelpSearchHit
{
    /** Mark the matched words in {@see $excerpt}; characters that do not occur in page text. */
    public const string MATCH_START = '⟦';
    public const string MATCH_END = '⟧';

    public function __construct(
        public int $pageId,
        public string $title,
        public string $path,
        public string $excerpt,
        /** Short description of the page (#1578); shown instead of the excerpt when there is one. */
        public ?string $summary = null,
    ) {
    }

    /**
     * The excerpt as HTML: escaped text with the matched words in <mark>.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the help search template.
     */
    public function excerptHtml(): string
    {
        return str_replace(
            [self::MATCH_START, self::MATCH_END],
            ['<mark>', '</mark>'],
            htmlspecialchars($this->excerpt, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8'),
        );
    }
}
