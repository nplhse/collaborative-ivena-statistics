<?php

declare(strict_types=1);

namespace App\Service\Statistics\TransportTime;

use App\Model\Scope;
use App\Query\TransportTimeReader;
use App\Query\TransportTimeWriter;
use App\Repository\AggScopeRepository;

final class TransportTimeAggregator
{
    private ?Scope $scope = null;

    private ?int $aggScopeId = null;

    private bool $doCoreBuckets = false;

    /** @var string[] */
    private array $dimensionTypes = [];

    private ?int $dimensionLimit = null;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly AggScopeRepository $aggScopeRepository,
        private readonly TransportTimeReader $reader,
        private readonly TransportTimeWriter $writer,
    ) {
    }

    public function forScope(Scope $scope): self
    {
        $clone = clone $this;
        $clone->scope = $scope;
        $clone->aggScopeId = $this->aggScopeRepository->ensureIdForScope($scope);

        return $clone;
    }

    public function withCoreBuckets(): self
    {
        $this->doCoreBuckets = true;

        return $this;
    }

    /**
     * @param string[] $dimTypes
     */
    public function withDimensions(array $dimTypes): self
    {
        $this->dimensionTypes = array_values(array_unique(array_merge($this->dimensionTypes, $dimTypes)));

        return $this;
    }

    public function withDimensionLimit(?int $limit): self
    {
        if (null === $limit || $limit <= 0) {
            $this->dimensionLimit = null;
        } else {
            $this->dimensionLimit = $limit;
        }

        return $this;
    }

    public function execute(): void
    {
        if (null === $this->scope || null === $this->aggScopeId) {
            throw new \LogicException('Call forScope() before execute().');
        }

        // Core buckets
        if ($this->doCoreBuckets) {
            $core = $this->reader->fetchCoreBuckets($this->scope);

            $this->writer->saveCoreBuckets(
                $this->aggScopeId,
                $core['payload'],
                $core['mean'],
                $core['variance'],
                $core['stddev'],
            );
        }

        foreach ($this->dimensionTypes as $dimType) {
            $rows = $this->reader->fetchDimensionBuckets($this->scope, $dimType, $this->dimensionLimit);

            if (!$rows) {
                continue;
            }

            $this->writer->saveDimensionBuckets($this->aggScopeId, $dimType, $rows);
        }
    }
}
