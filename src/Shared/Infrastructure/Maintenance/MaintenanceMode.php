<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maintenance mode (#1680, ADR 046): on while the flag file exists. The file lies in a directory that Deployer shares
 * between releases, so the old and the new release see the same switch. Its modification time is "since".
 */
final readonly class MaintenanceMode
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        #[Autowire(param: 'app.maintenance_flag_file')]
        private string $flagFile,
    ) {
    }

    public function isEnabled(): bool
    {
        clearstatcache(true, $this->flagFile);

        return is_file($this->flagFile);
    }

    public function enabledSince(): ?\DateTimeImmutable
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $modified = filemtime($this->flagFile);

        return false === $modified ? null : new \DateTimeImmutable('@'.$modified);
    }

    /** Keeps the time of an earlier switch-on, so "since" stays the start of the maintenance. */
    public function enable(): void
    {
        if ($this->isEnabled()) {
            return;
        }

        $directory = \dirname($this->flagFile);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the directory "%s".', $directory));
        }

        if (false === file_put_contents($this->flagFile, '')) {
            throw new \RuntimeException(\sprintf('Cannot write the maintenance flag "%s".', $this->flagFile));
        }
    }

    public function disable(): void
    {
        if ($this->isEnabled() && !unlink($this->flagFile)) {
            throw new \RuntimeException(\sprintf('Cannot remove the maintenance flag "%s".', $this->flagFile));
        }
    }
}
