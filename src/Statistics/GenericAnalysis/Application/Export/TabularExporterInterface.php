<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Export;

interface TabularExporterInterface
{
    public function supports(string $format): bool;

    public function contentType(): string;

    public function fileExtension(): string;

    /**
     * @param resource $stream
     */
    public function export(TabularExportDocument $document, $stream): void;
}
