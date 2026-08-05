<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Entity;

use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsRequestRepository::class)]
#[ORM\Table(name: 'analytics_request')]
#[ORM\Index(name: 'idx_analytics_request_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_analytics_request_route_name', columns: ['route_name'])]
#[ORM\Index(name: 'idx_analytics_request_feature_area', columns: ['feature_area'])]
#[ORM\Index(name: 'idx_analytics_request_occurred_feature', columns: ['occurred_at', 'feature_area'])]
class AnalyticsRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $routeName;

    #[ORM\Column(length: 32, enumType: FeatureArea::class)]
    private FeatureArea $featureArea;

    #[ORM\Column]
    private int $httpStatus;

    #[ORM\Column]
    private int $durationMs;

    #[ORM\Column]
    private int $dbQueryCount;

    #[ORM\Column]
    private int $dbTimeMs;

    #[ORM\Column]
    private bool $isAuthenticated;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $userRole;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $analyticsUserKey;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $visitorKey;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $sessionKey;

    #[ORM\Column(length: 16, enumType: BrowserFamily::class)]
    private BrowserFamily $browserFamily;

    #[ORM\Column(length: 16, enumType: DeviceType::class)]
    private DeviceType $deviceType;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $queryParamNames;

    /**
     * @param list<string> $queryParamNames
     */
    public function __construct(
        \DateTimeImmutable $occurredAt,
        ?string $routeName,
        FeatureArea $featureArea,
        int $httpStatus,
        int $durationMs,
        int $dbQueryCount,
        int $dbTimeMs,
        bool $isAuthenticated,
        ?string $userRole,
        ?string $analyticsUserKey,
        ?string $visitorKey,
        ?string $sessionKey,
        BrowserFamily $browserFamily,
        DeviceType $deviceType,
        array $queryParamNames,
    ) {
        $this->occurredAt = $occurredAt;
        $this->routeName = $routeName;
        $this->featureArea = $featureArea;
        $this->httpStatus = $httpStatus;
        $this->durationMs = $durationMs;
        $this->dbQueryCount = $dbQueryCount;
        $this->dbTimeMs = $dbTimeMs;
        $this->isAuthenticated = $isAuthenticated;
        $this->userRole = $userRole;
        $this->analyticsUserKey = $analyticsUserKey;
        $this->visitorKey = $visitorKey;
        $this->sessionKey = $sessionKey;
        $this->browserFamily = $browserFamily;
        $this->deviceType = $deviceType;
        $this->queryParamNames = $queryParamNames;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function getFeatureArea(): FeatureArea
    {
        return $this->featureArea;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getDbQueryCount(): int
    {
        return $this->dbQueryCount;
    }

    public function getDbTimeMs(): int
    {
        return $this->dbTimeMs;
    }

    public function isAuthenticated(): bool
    {
        return $this->isAuthenticated;
    }

    public function getUserRole(): ?string
    {
        return $this->userRole;
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

    public function getBrowserFamily(): BrowserFamily
    {
        return $this->browserFamily;
    }

    public function getDeviceType(): DeviceType
    {
        return $this->deviceType;
    }

    /**
     * @return list<string>
     */
    public function getQueryParamNames(): array
    {
        return $this->queryParamNames;
    }
}
