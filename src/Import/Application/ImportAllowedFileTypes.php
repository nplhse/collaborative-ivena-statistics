<?php

declare(strict_types=1);

namespace App\Import\Application;

final class ImportAllowedFileTypes
{
    /** @var list<string> */
    public const array EXTENSIONS = ['csv', 'txt'];

    /** @var list<string> */
    public const array MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/vnd.ms-excel',
    ];

    /**
     * Extension-to-MIME mapping for the Symfony File constraint.
     * Explicit per-extension MIME lists are required because Symfony intersects
     * extension MIME types with mimeTypes, which would otherwise reject
     * text/plain content in .csv files.
     *
     * @var array<string, list<string>>
     */
    public const array EXTENSION_MIME_MAP = [
        'csv' => [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
            'application/csv',
            'text/x-comma-separated-values',
            'text/x-csv',
        ],
        'txt' => ['text/plain'],
    ];

    /** @var list<string> */
    public const array REJECTED_EXTENSIONS = ['xls', 'xlsx'];

    private function __construct()
    {
    }
}
