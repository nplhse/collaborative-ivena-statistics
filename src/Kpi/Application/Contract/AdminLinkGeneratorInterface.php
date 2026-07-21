<?php

declare(strict_types=1);

namespace App\Kpi\Application\Contract;

interface AdminLinkGeneratorInterface
{
    public function hospitalCrudIndexUrl(): string;

    public function importCrudIndexUrl(): string;

    public function allocationCrudIndexUrl(): string;

    public function importRejectCrudIndexUrl(): string;

    public function failedImportsCrudIndexUrl(): string;

    public function importDetailUrl(int $importId): string;
}
