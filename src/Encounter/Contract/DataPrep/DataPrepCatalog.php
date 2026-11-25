<?php

declare(strict_types=1);

namespace App\Encounter\Contract\DataPrep;

/**
 * A catalog of a data preparation contribution (#1301): the payload bundled into the tool as offline fallback and
 * the public route the tool loads the maintained catalog from at start (allowed in the tool's CSP). Without a route
 * the payload is only bundled when the tool is downloaded, for data that must not be public (#1638).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the tool renderer.
 */
final readonly class DataPrepCatalog
{
    /**
     * @param array<string, mixed> $payload in the format the schema module parses
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037).
     */
    public function __construct(
        public string $id,
        public array $payload,
        public ?string $route = null,
    ) {
    }
}
