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

    /** Separates the passages of {@see $excerpt} taken from different places in the text. */
    public const string PASSAGE_BREAK = '⟬';

    public function __construct(
        public int $pageId,
        public string $title,
        public string $path,
        public string $excerpt,
        /** Short description of the page (#1578); shown above the excerpt. */
        public ?string $summary = null,
        /** The searched text the excerpt was taken from; tells whether a passage starts or ends inside it. */
        private ?string $text = null,
    ) {
    }

    /**
     * Whether the excerpt shows a match in the text. A match only in the title or short description yields the start
     * of the text without marks, which tells the reader nothing about the hit.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the help search template.
     */
    public function excerptHasMatch(): bool
    {
        return str_contains($this->excerpt, self::MATCH_START);
    }

    /**
     * The passages of the excerpt (#1590), each as HTML (escaped, matched words in <mark>) with whether it starts or
     * ends inside the text, so the page can show "[…]" there. Without the text both are unknown and false.
     *
     * @return list<array{html: string, cutBefore: bool, cutAfter: bool}>
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the help search template.
     */
    public function passages(): array
    {
        $text = null === $this->text ? null : $this->normalize($this->text);
        $passages = [];
        foreach (explode(self::PASSAGE_BREAK, $this->excerpt) as $passage) {
            $passage = trim($passage);
            if ('' === $passage) {
                continue;
            }

            $plain = $this->normalize(str_replace([self::MATCH_START, self::MATCH_END], '', $passage));
            $position = null === $text || '' === $plain ? false : mb_strpos($text, $plain);
            $passages[] = [
                'html' => str_replace(
                    [self::MATCH_START, self::MATCH_END],
                    ['<mark>', '</mark>'],
                    htmlspecialchars($passage, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8'),
                ),
                'cutBefore' => null !== $text && (false === $position || $this->hasWords(mb_substr($text, 0, $position))),
                'cutAfter' => null !== $text && (false === $position || $this->hasWords(mb_substr($text, $position + mb_strlen($plain)))),
            ];
        }

        return $passages;
    }

    /** Single spaces and no punctuation at the edges, as the excerpt leaves out a final full stop. */
    private function normalize(string $text): string
    {
        return (string) preg_replace(['/\s+/u', '/^[\s\p{P}]+|[\s\p{P}]+$/u'], [' ', ''], $text);
    }

    private function hasWords(string $text): bool
    {
        return 1 === preg_match('/[\p{L}\p{N}]/u', $text);
    }
}
