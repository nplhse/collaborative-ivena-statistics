<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Benchmarking\Application\BenchmarkDefaultResolver;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BenchmarkDefaultResolverTest extends TestCase
{
    public function testRedirectsToPublicScopesForUserWithoutHospitalAccess(): void
    {
        $hospitalAccess = $this->createStub(HospitalAccessInterface::class);
        $hospitalAccess->method('canUseBenchmarkingScope')->willReturn(false);

        $resolver = new BenchmarkDefaultResolver($hospitalAccess);
        $payload = $resolver->maybeRedirectPayload(new Request(), $this->createStub(User::class));

        self::assertNotNull($payload);
        self::assertSame('public', $payload['query']['scope']);
        self::assertSame('all', $payload['query']['period']);
        self::assertSame('public', $payload['query'][StatisticsQueryKeys::COMPARISON_SCOPE]);
        self::assertSame('all_time', $payload['query'][StatisticsQueryKeys::COMPARISON_PERIOD]);
    }

    public function testRedirectsToMyHospitalsAndHospitalCohortForParticipant(): void
    {
        $hospitalAccess = $this->createStub(HospitalAccessInterface::class);
        $hospitalAccess->method('canUseBenchmarkingScope')->willReturn(true);

        $resolver = new BenchmarkDefaultResolver($hospitalAccess);
        $payload = $resolver->maybeRedirectPayload(
            new Request(),
            $this->createStub(User::class),
        );

        self::assertNotNull($payload);
        self::assertSame('my_hospitals', $payload['query']['scope']);
        self::assertSame('all', $payload['query']['period']);
        self::assertSame('hospital_cohort', $payload['query'][StatisticsQueryKeys::COMPARISON_SCOPE]);
        self::assertSame('all_time', $payload['query'][StatisticsQueryKeys::COMPARISON_PERIOD]);
    }

    public function testDoesNotRedirectWhenComparisonScopePresent(): void
    {
        $hospitalAccess = $this->createStub(HospitalAccessInterface::class);

        $resolver = new BenchmarkDefaultResolver($hospitalAccess);
        $request = new Request([StatisticsQueryKeys::COMPARISON_SCOPE => 'public']);

        self::assertNull($resolver->maybeRedirectPayload($request, null));
    }
}
