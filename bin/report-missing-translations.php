#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Shared\Application\Translation\MissingTranslationReport;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['wave:', 'format:', 'list-keys', 'domain:', 'fail-threshold:']);

$wave = $options['wave'] ?? null;
if (null !== $wave && !\in_array($wave, [...array_keys(MissingTranslationReport::WAVES), 'other', 'no_de_file', 'all'], true)) {
    fwrite(STDERR, sprintf(
        "Unknown wave %s. Allowed: all, %s, other, no_de_file\n",
        $wave,
        implode(', ', array_keys(MissingTranslationReport::WAVES)),
    ));
    exit(1);
}

$format = $options['format'] ?? 'text';
if (!\in_array($format, ['text', 'markdown'], true)) {
    fwrite(STDERR, "Unknown format {$format}. Allowed: text, markdown\n");
    exit(1);
}

$listKeys = isset($options['list-keys']);
$failThreshold = isset($options['fail-threshold']) ? (int) $options['fail-threshold'] : null;

$domains = null;
if (isset($options['domain'])) {
    $domains = array_map(trim(...), explode(',', (string) $options['domain']));
}

$report = new MissingTranslationReport(dirname(__DIR__).'/translations');
$waveFilter = (null === $wave || 'all' === $wave) ? null : $wave;
$sections = $report->build($domains, $waveFilter);
$summary = $report->summarize($sections);

if ('markdown' === $format) {
    echo "# Missing German translations\n\n";
    echo sprintf("- Missing keys: **%d**\n", $summary['missing']);
    echo sprintf("- Existing keys: **%d**\n\n", $summary['existing']);

    echo "## By wave\n\n";
    echo "| Wave | Missing | Existing |\n";
    echo "|---|---:|---:|\n";
    foreach ($summary['byWave'] as $waveName => $counts) {
        echo sprintf("| %s | %d | %d |\n", $waveName, $counts['missing'], $counts['existing']);
    }
    echo "\n";

    foreach ($sections as $section) {
        if ([] === $section['missing']) {
            continue;
        }

        echo sprintf("## %s / %s (%d missing)\n\n", $section['domain'], $section['wave'], \count($section['missing']));
        foreach ($section['missing'] as $key) {
            echo sprintf("- `%s`\n", $key);
        }
        echo "\n";
    }
} else {
    echo sprintf("Missing DE translation keys: %d\n", $summary['missing']);
    echo sprintf("Existing DE translation keys: %d\n\n", $summary['existing']);

    echo "By wave:\n";
    foreach ($summary['byWave'] as $waveName => $counts) {
        echo sprintf("  %-12s missing=%-5d existing=%d\n", $waveName.':', $counts['missing'], $counts['existing']);
    }
    echo "\n";

    foreach ($sections as $section) {
        if ([] === $section['missing']) {
            continue;
        }

        echo sprintf(
            "[%s / %s] %d missing\n",
            $section['domain'],
            $section['wave'],
            \count($section['missing']),
        );

        if ($listKeys) {
            foreach ($section['missing'] as $key) {
                echo "  {$key}\n";
            }
        }
    }
}

if (null !== $failThreshold && $summary['missing'] > $failThreshold) {
    fwrite(STDERR, sprintf("\nMissing key count %d exceeds threshold %d.\n", $summary['missing'], $failThreshold));
    exit(1);
}

exit(0);
