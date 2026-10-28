<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

/**
 * Which patterns generated an import: the source slot of the import marker (ADR 022).
 *
 * The slot is `<pattern source>+<structure source>`; each part is `defaults` or the checksum of a
 * package. The structure part (hospital structure packages, #876) appears only when it is not
 * `defaults`, so `defaults` alone means code defaults for both. Build and parse the slot only here.
 */
final readonly class PatternSource
{
    public const string DEFAULTS = 'defaults';

    /** One part of the slot: `defaults` or a checksum. */
    public const string PART = '[a-z0-9-]{1,64}';

    /** The whole slot, for the marker pattern. */
    public const string SLOT = self::PART.'(?:\+'.self::PART.')?';

    public function __construct(
        public string $patterns = self::DEFAULTS,
        public string $structures = self::DEFAULTS,
    ) {
        foreach ([$patterns, $structures] as $part) {
            if (1 !== preg_match('#^'.self::PART.'$#', $part)) {
                throw new \InvalidArgumentException(sprintf('Invalid pattern source "%s": expected "defaults" or a checksum (lowercase letters, digits and dashes).', $part));
            }
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * @throws \InvalidArgumentException for a slot that is not `<part>` or `<part>+<part>`
     */
    public static function fromSlot(string $slot): self
    {
        if (1 !== preg_match('#^(?<patterns>'.self::PART.')(?:\+(?<structures>'.self::PART.'))?$#', $slot, $match)) {
            throw new \InvalidArgumentException(sprintf('Invalid pattern source slot "%s".', $slot));
        }

        return new self($match['patterns'], '' !== ($match['structures'] ?? '') ? $match['structures'] : self::DEFAULTS);
    }

    public function slot(): string
    {
        return self::DEFAULTS === $this->structures ? $this->patterns : $this->patterns.'+'.$this->structures;
    }
}
