<?php

declare(strict_types=1);

namespace App\Research\UI\Twig;

use Doctrine\SqlFormatter\HtmlHighlighter;
use Doctrine\SqlFormatter\SqlFormatter;
use Twig\Attribute\AsTwigFilter;

/**
 * Twig helpers of the Research context.
 */
final class ResearchTwigExtension
{
    /**
     * Formats and highlights the SQL of an export query for reading (ADR 045). The formatter escapes the SQL; the
     * colours come from theme classes, so they work in light and dark mode.
     */
    #[AsTwigFilter(name: 'research_sql', isSafe: ['html'])]
    public function formatSql(string $sql): string
    {
        $highlighter = new HtmlHighlighter([
            HtmlHighlighter::HIGHLIGHT_QUOTE => 'class="text-green"',
            HtmlHighlighter::HIGHLIGHT_BACKTICK_QUOTE => 'class="text-purple"',
            HtmlHighlighter::HIGHLIGHT_RESERVED => 'class="fw-bold text-blue"',
            HtmlHighlighter::HIGHLIGHT_BOUNDARY => '',
            HtmlHighlighter::HIGHLIGHT_NUMBER => 'class="text-orange"',
            HtmlHighlighter::HIGHLIGHT_WORD => '',
            HtmlHighlighter::HIGHLIGHT_ERROR => 'class="bg-red-lt"',
            HtmlHighlighter::HIGHLIGHT_COMMENT => 'class="text-secondary"',
            HtmlHighlighter::HIGHLIGHT_VARIABLE => 'class="text-orange"',
            HtmlHighlighter::HIGHLIGHT_PRE => 'class="p-3 mb-0 bg-body-tertiary text-body rounded small text-wrap" data-testid="data-request-query-sql"',
        ]);

        return new SqlFormatter($highlighter)->format($sql);
    }
}
