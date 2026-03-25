<?php

$finder = new PhpCsFixer\Finder()
    // Limit to application and test code. Migrations are intentionally excluded
    // to keep formatting/semantics changes scoped and predictable.
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->exclude('var')
;

return new PhpCsFixer\Config()
    ->setRules([
        '@Symfony' => true,

        // Do not edit @psalm-suppress comments
        'phpdoc_to_comment' => [
            'ignored_tags' => ['psalm-suppress'],
        ],
        // Adds `declare(strict_types=1);` to PHP files
        'declare_strict_types' => [
            'preserve_existing_declaration' => true,
        ],
    ])
    ->setFinder($finder)
;
