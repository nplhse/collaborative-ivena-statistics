<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exporter')]
interface ExporterInterface
{
    public function key(): string;

    public function assertCanExport(User $user): void;

    /**
     * @return list<int>
     */
    public function resolveScopeHospitalIds(User $user): array;

    public function count(User $user, object $criteria): int;

    /**
     * @param resource $stream
     *
     * @return int number of data rows written (excluding header)
     */
    public function writeCsv(User $user, object $criteria, $stream): int;

    public function buildFilename(): string;

    /**
     * @return array<string, mixed>
     */
    public function serializeCriteria(object $criteria): array;
}
