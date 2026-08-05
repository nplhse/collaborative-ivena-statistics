<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsProductEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stub entity for product events (adoption, onboarding, export, …).
 */
#[ORM\Entity(repositoryClass: AnalyticsProductEventRepository::class)]
#[ORM\Table(name: 'analytics_product_event')]
#[ORM\Index(name: 'idx_analytics_product_event_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_analytics_product_event_name', columns: ['event_name'])]
class AnalyticsProductEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 120)]
    private string $eventName;

    #[ORM\Column(length: 32, nullable: true, enumType: FeatureArea::class)]
    private ?FeatureArea $featureArea;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $analyticsUserKey;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $visitorKey;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $sessionKey;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $context;

    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        string $eventName,
        ?FeatureArea $featureArea = null,
        ?string $analyticsUserKey = null,
        ?string $visitorKey = null,
        ?string $sessionKey = null,
        array $context = [],
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->eventName = $eventName;
        $this->featureArea = $featureArea;
        $this->analyticsUserKey = $analyticsUserKey;
        $this->visitorKey = $visitorKey;
        $this->sessionKey = $sessionKey;
        $this->context = $context;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getFeatureArea(): ?FeatureArea
    {
        return $this->featureArea;
    }

    public function getAnalyticsUserKey(): ?string
    {
        return $this->analyticsUserKey;
    }

    public function getVisitorKey(): ?string
    {
        return $this->visitorKey;
    }

    public function getSessionKey(): ?string
    {
        return $this->sessionKey;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
