<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

/**
 * How one generator name or PZC code resolves against the installed catalog.
 */
final readonly class CatalogMatch
{
    public const string RESOLVED = 'resolved';

    public const string MISSING = 'missing';

    public const string AMBIGUOUS = 'ambiguous';

    /**
     * @param list<string> $candidates catalog names an ambiguous name matches
     */
    private function __construct(
        public string $status,
        public string $name,
        public ?int $id = null,
        public ?string $catalogName = null,
        public ?string $via = null,
        public array $candidates = [],
        public ?string $problem = null,
        public ?string $warning = null,
        public ?int $rawId = null,
        public ?string $rawName = null,
    ) {
    }

    public static function resolved(string $name, int $id, string $catalogName, string $via, ?string $warning = null, ?int $rawId = null, ?string $rawName = null): self
    {
        return new self(self::RESOLVED, $name, $id, $catalogName, $via, warning: $warning, rawId: $rawId, rawName: $rawName);
    }

    public static function missing(string $name, string $problem): self
    {
        return new self(self::MISSING, $name, problem: $problem);
    }

    /**
     * @param list<string> $candidates
     */
    public static function ambiguous(string $name, array $candidates, string $problem): self
    {
        return new self(self::AMBIGUOUS, $name, candidates: $candidates, problem: $problem);
    }

    /**
     * The same result under another generator name, for example the department a speciality is written for.
     */
    public function for(string $name, string $context): self
    {
        return new self($this->status, $name, $this->id, $this->catalogName, $this->via, $this->candidates, null !== $this->problem ? $context.': '.$this->problem : null, $this->warning, $this->rawId, $this->rawName);
    }

    public function isResolved(): bool
    {
        return self::RESOLVED === $this->status;
    }
}
