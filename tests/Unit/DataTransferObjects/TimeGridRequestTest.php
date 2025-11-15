<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataTransferObjects;

use App\DataTransferObjects\TimeGridRequest;
use App\Enum\TimeGridMode;
use App\Model\Scope;
use App\Service\Statistics\Util\Period;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TimeGridRequestTest extends TestCase
{
    public function testFromRequestUsesDefaultsWhenNoQueryParams(): void
    {
        $request = new Request(); // leere Query

        $dto = TimeGridRequest::fromRequest($request);

        self::assertSame(Period::YEAR, $dto->granularity);
        self::assertSame(
            Period::normalizePeriodKey(Period::YEAR, '2021-01-01'),
            $dto->periodKey
        );
        self::assertSame('default', $dto->metricsPreset);
        self::assertSame(TimeGridMode::RAW, $dto->mode);
        self::assertSame('int', $dto->view); // Default im Code ist 'int'
        self::assertSame('public', $dto->primaryType);
        self::assertSame('all', $dto->primaryId);
        self::assertNull($dto->baseType);
        self::assertNull($dto->baseId);
    }

    public function testFromRequestNormalizesValidGranularityAndPeriodKey(): void
    {
        $request = new Request([
            'gran' => 'month',
            'key' => '2025-11-08',
        ]);

        $dto = TimeGridRequest::fromRequest($request);

        self::assertSame(Period::MONTH, $dto->granularity);
        self::assertSame(
            Period::normalizePeriodKey(Period::MONTH, '2025-11-08'),
            $dto->periodKey
        );
    }

    public function testFromRequestFallsBackToYearWhenGranularityInvalid(): void
    {
        $request = new Request([
            'gran' => 'invalid-gran',
            'key' => '2025-11-08',
        ]);

        $dto = TimeGridRequest::fromRequest($request);

        self::assertSame(
            Period::YEAR,
            $dto->granularity,
            'Unknown granularity should fall back to YEAR.'
        );

        self::assertSame(
            Period::normalizePeriodKey(Period::YEAR, '2025-11-08'),
            $dto->periodKey
        );
    }

    public function testFromRequestSetsMetricsPresetFromQuery(): void
    {
        $request = new Request([
            'metrics' => 'gender',
        ]);

        $dto = TimeGridRequest::fromRequest($request);

        self::assertSame('gender', $dto->metricsPreset);
    }

    public function testFromRequestParsesModeEnumCaseInsensitive(): void
    {
        $request = new Request([
            'mode' => 'compare', // kleingeschrieben
        ]);

        $dto = TimeGridRequest::fromRequest($request);

        self::assertSame(TimeGridMode::COMPARE, $dto->mode);
    }

    public function testFromRequestDefaultsModeToRawOnInvalidValue(): void
    {
        $request = new Request([
            'mode' => 'not-a-valid-mode',
        ]);

        $dto = TimeGridRequest::fromRequest($request);

        self::assertSame(TimeGridMode::RAW, $dto->mode);
    }

    public function testFromRequestParsesViewPctOrIntWithDefaultInt(): void
    {
        $requestPct = new Request([
            'view' => 'pct',
        ]);

        $dtoPct = TimeGridRequest::fromRequest($requestPct);
        self::assertSame('pct', $dtoPct->view);

        $requestInt = new Request([
            'view' => 'int',
        ]);
        $dtoInt = TimeGridRequest::fromRequest($requestInt);
        self::assertSame('int', $dtoInt->view);

        // Irgendein anderer Wert -> int
        $requestOther = new Request([
            'view' => 'counts',
        ]);
        $dtoOther = TimeGridRequest::fromRequest($requestOther);
        self::assertSame('int', $dtoOther->view);

        // Kein Parameter -> default 'int'
        $requestDefault = new Request();
        $dtoDefault = TimeGridRequest::fromRequest($requestDefault);
        self::assertSame('int', $dtoDefault->view);
    }

    public function testFromRequestMapsPrimaryScopeFromQueryOrDefaults(): void
    {
        $request = new Request([
            'primaryType' => 'state',
            'primaryId' => 'BY',
        ]);

        $dto = TimeGridRequest::fromRequest($request);

        self::assertSame('state', $dto->primaryType);
        self::assertSame('BY', $dto->primaryId);
    }

    public function testFromRequestMapsBaseScopeAndNormalizesEmptyStringToNull(): void
    {
        // Fall 1: beide gesetzt
        $requestFull = new Request([
            'baseType' => 'state',
            'baseId' => 'BY',
        ]);

        $dtoFull = TimeGridRequest::fromRequest($requestFull);
        self::assertSame('state', $dtoFull->baseType);
        self::assertSame('BY', $dtoFull->baseId);

        // Fall 2: leere Strings -> null
        $requestEmpty = new Request([
            'baseType' => '',
            'baseId' => '',
        ]);

        $dtoEmpty = TimeGridRequest::fromRequest($requestEmpty);
        self::assertNull($dtoEmpty->baseType);
        self::assertNull($dtoEmpty->baseId);

        // Fall 3: Parameter fehlen -> null
        $requestMissing = new Request();
        $dtoMissing = TimeGridRequest::fromRequest($requestMissing);
        self::assertNull($dtoMissing->baseType);
        self::assertNull($dtoMissing->baseId);
    }

    public function testToPrimaryScopeBuildsScopeFromDto(): void
    {
        $request = new Request([
            'gran' => 'month',
            'key' => '2025-11-08',
            'primaryType' => 'state',
            'primaryId' => 'BY',
        ]);

        $dto = TimeGridRequest::fromRequest($request);
        $scope = $dto->toPrimaryScope();

        self::assertSame('state', $scope->scopeType);
        self::assertSame('BY', $scope->scopeId);
        self::assertSame($dto->granularity, $scope->granularity);
        self::assertSame($dto->periodKey, $scope->periodKey);
    }

    public function testToBaseScopeOrNullReturnsNullWhenModeIsNotCompare(): void
    {
        $request = new Request([
            'baseType' => 'state',
            'baseId' => 'BY',
            'mode' => 'raw',
        ]);

        $dto = TimeGridRequest::fromRequest($request);
        self::assertSame(TimeGridMode::RAW, $dto->mode);

        $baseScope = $dto->toBaseScopeOrNull();

        self::assertNull($baseScope);
    }

    public function testToBaseScopeOrNullReturnsNullWhenBaseScopeIncomplete(): void
    {
        // Mode = COMPARE, aber baseId fehlt
        $requestMissingId = new Request([
            'mode' => 'compare',
            'baseType' => 'state',
        ]);

        $dtoMissingId = TimeGridRequest::fromRequest($requestMissingId);
        self::assertSame(TimeGridMode::COMPARE, $dtoMissingId->mode);
        self::assertNull($dtoMissingId->toBaseScopeOrNull());

        // Mode = COMPARE, aber baseType fehlt
        $requestMissingType = new Request([
            'mode' => 'compare',
            'baseId' => 'BY',
        ]);

        $dtoMissingType = TimeGridRequest::fromRequest($requestMissingType);
        self::assertSame(TimeGridMode::COMPARE, $dtoMissingType->mode);
        self::assertNull($dtoMissingType->toBaseScopeOrNull());
    }

    public function testToBaseScopeOrNullReturnsScopeWhenCompareAndBaseScopeComplete(): void
    {
        $request = new Request([
            'mode' => 'compare',
            'baseType' => 'state',
            'baseId' => 'BY',
            'gran' => 'month',
            'key' => '2025-11-08',
        ]);

        $dto = TimeGridRequest::fromRequest($request);

        self::assertSame(TimeGridMode::COMPARE, $dto->mode);

        $baseScope = $dto->toBaseScopeOrNull();

        self::assertInstanceOf(Scope::class, $baseScope);
        self::assertSame('state', $baseScope->scopeType);
        self::assertSame('BY', $baseScope->scopeId);
        self::assertSame($dto->granularity, $baseScope->granularity);
        self::assertSame($dto->periodKey, $baseScope->periodKey);
    }
}
