<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The configured source directory of every file component (ADR 038, "Components").
 *
 * The paths follow the application parameters, so the demo environment (ADR 023) backs up its own storage.
 * Pending uploads (#977) are left out of imports: they are temporary and purged after 24 hours.
 */
final readonly class BackupSources
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        #[Autowire(param: 'app.imports_base_dir')]
        private string $importsDirectory,
        #[Autowire(param: 'app.media_upload_dir')]
        private string $mediaDirectory,
        #[Autowire(param: 'app.hospital_isochrones_dir')]
        private string $geoDirectory,
        #[Autowire(param: 'app.backup.reference_dir')]
        private string $referenceDirectory,
    ) {
    }

    /**
     * @return list<FileComponentSource>
     */
    public function fileComponents(): array
    {
        return [
            new FileComponentSource(BackupComponent::Imports, $this->importsDirectory, ['pending']),
            new FileComponentSource(BackupComponent::Media, $this->mediaDirectory),
            new FileComponentSource(BackupComponent::Geo, $this->geoDirectory),
            new FileComponentSource(BackupComponent::Reference, $this->referenceDirectory),
        ];
    }
}
