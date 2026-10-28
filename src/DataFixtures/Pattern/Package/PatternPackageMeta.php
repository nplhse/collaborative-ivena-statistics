<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

/**
 * The `meta` block of a pattern package (ADR 022).
 *
 * The shape does not depend on the format, so the structural pattern packages of #876 can reuse it:
 * format and version say what the file is, `source` who made it (`defaults`, `export`, …), `catalog`
 * which reference catalog the values belong to, and the checksum covers everything except `meta`.
 */
final readonly class PatternPackageMeta
{
    /**
     * @param array<string, int> $counts
     */
    public function __construct(
        public string $format,
        public int $version,
        public string $createdAt,
        public string $source,
        public array $counts,
        public PatternPackageChecksum $checksum,
        public ?string $appVersion = null,
        public ?string $catalog = null,
        public ?string $notes = null,
    ) {
    }

    /**
     * @param array<string, int> $counts
     */
    public function withSeal(array $counts, PatternPackageChecksum $checksum): self
    {
        return new self($this->format, $this->version, $this->createdAt, $this->source, $counts, $checksum, $this->appVersion, $this->catalog, $this->notes);
    }

    /**
     * @param array{format: string, version: int, created_at: string, source: string, counts: array<string, int>, checksum: array{algorithm: string, value: string}, app_version?: string, catalog?: string, notes?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['format'],
            $data['version'],
            $data['created_at'],
            $data['source'],
            $data['counts'],
            new PatternPackageChecksum($data['checksum']['algorithm'], $data['checksum']['value']),
            $data['app_version'] ?? null,
            $data['catalog'] ?? null,
            $data['notes'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'format' => $this->format,
            'version' => $this->version,
            'created_at' => $this->createdAt,
            'source' => $this->source,
            'app_version' => $this->appVersion,
            'catalog' => $this->catalog,
            'counts' => $this->counts,
            'checksum' => $this->checksum->toArray(),
            'notes' => $this->notes,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
