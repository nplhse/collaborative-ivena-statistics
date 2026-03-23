<?php

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude('var')
;

return new PhpCsFixer\Config()
    ->setRules([
        '@Symfony' => true,

        // Do not edit @psalm-suppress comments
        'phpdoc_to_comment' => [
            'ignored_tags' => ['psalm-suppress'],
        ],
    ])
    ->setFinder($finder)
;
