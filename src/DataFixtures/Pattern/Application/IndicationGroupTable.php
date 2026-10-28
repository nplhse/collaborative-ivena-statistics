<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

/**
 * The indication groups of a pattern set, keyed by PZC code prefix (ADR 022).
 *
 * The longest matching prefix wins, so `332` (STEMI) refines `33` (chest pain), which refines `3`;
 * codes without a group, and cases without an indication, get the fallback group.
 *
 * @phpstan-import-type Group from IndicationGroups
 */
final readonly class IndicationGroupTable
{
    /**
     * @param array<array-key, Group> $groups   PZC code prefix → group; departments by default label
     * @param string                  $fallback prefix of the group for codes without a group
     */
    public function __construct(
        private array $groups,
        private string $fallback,
    ) {
        if (!isset($this->groups[$this->fallback])) {
            throw new \InvalidArgumentException(sprintf('The fallback group "%s" is not in the table.', $this->fallback));
        }
    }

    public static function defaults(): self
    {
        return new self(IndicationGroups::all(), IndicationGroups::FALLBACK);
    }

    /**
     * @return Group
     */
    public function forCode(string $code): array
    {
        for ($length = \strlen($code); $length > 0; --$length) {
            // PHP keys numeric prefixes as int; codes starting with 0 (no patient) have no group.
            $prefix = substr($code, 0, $length);
            if ('0' !== $prefix[0] && ctype_digit($prefix) && isset($this->groups[(int) $prefix])) {
                return $this->groups[(int) $prefix];
            }
        }

        return $this->groups[$this->fallback];
    }

    /**
     * @return array<array-key, Group>
     */
    public function all(): array
    {
        return $this->groups;
    }

    public function fallback(): string
    {
        return $this->fallback;
    }
}
