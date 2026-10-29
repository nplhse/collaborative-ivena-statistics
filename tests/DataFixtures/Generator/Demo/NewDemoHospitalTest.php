<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Demo;

use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Generator\Application\Demo\DemoHospitalRequest;
use App\DataFixtures\Generator\Application\Demo\NewDemoHospital;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewDemoHospitalTest extends TestCase
{
    /**
     * The bed bands of the synthetic catalog: Small 80–299, Medium 300–599, Large 600–1300.
     */
    #[DataProvider('beds')]
    public function testTheSizeFollowsTheBeds(int $beds, HospitalSize $size): void
    {
        self::assertSame($size, new NewDemoHospital('A', HospitalTier::BASIC, $beds, 1)->size);
    }

    /**
     * @return iterable<string, array{int, HospitalSize}>
     */
    public static function beds(): iterable
    {
        yield '1 bed' => [1, HospitalSize::SMALL];
        yield '299 beds' => [299, HospitalSize::SMALL];
        yield '300 beds' => [300, HospitalSize::MEDIUM];
        yield '599 beds' => [599, HospitalSize::MEDIUM];
        yield '600 beds' => [600, HospitalSize::LARGE];
        yield '2000 beds, above the largest band' => [2000, HospitalSize::LARGE];
    }

    #[DataProvider('invalidHospitals')]
    public function testInvalidMasterDataIsRefused(\Closure $create, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $create();
    }

    /**
     * @return iterable<string, array{\Closure, string}>
     */
    public static function invalidHospitals(): iterable
    {
        yield 'no name' => [static fn (): NewDemoHospital => new NewDemoHospital(' ', HospitalTier::BASIC, 100, 1), 'needs a name'];
        yield 'no beds' => [static fn (): NewDemoHospital => new NewDemoHospital('A', HospitalTier::BASIC, 0, 1), 'Beds must be more than 0'];
        yield 'half coordinates' => [static fn (): NewDemoHospital => new NewDemoHospital('A', HospitalTier::BASIC, 100, 1, latitude: 50.0), 'both latitude and longitude'];
        yield 'latitude out of range' => [static fn (): NewDemoHospital => new NewDemoHospital('A', HospitalTier::BASIC, 100, 1, latitude: 91.0, longitude: 8.0), 'out of range'];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidSimulationsAreRefused(\Closure $create, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $create();
    }

    /**
     * @return iterable<string, array{\Closure, string}>
     */
    public static function invalidRequests(): iterable
    {
        $new = new NewDemoHospital('A', HospitalTier::BASIC, 100, 1);
        yield 'both entry points' => [static fn (): DemoHospitalRequest => new DemoHospitalRequest($new, 5, 1000, 12, 0.05), 'either a new hospital or an existing one'];
        yield 'no entry point' => [static fn (): DemoHospitalRequest => new DemoHospitalRequest(null, null, 1000, 12, 0.05), 'either a new hospital or an existing one'];
        yield 'no volume' => [static fn (): DemoHospitalRequest => new DemoHospitalRequest($new, null, 0, 12, 0.05), 'more than 0'];
        yield '9 months' => [static fn (): DemoHospitalRequest => new DemoHospitalRequest($new, null, 1000, 9, 0.05), '6, 12, 24 months'];
        yield 'closure share 1' => [static fn (): DemoHospitalRequest => new DemoHospitalRequest($new, null, 1000, 12, 1.0), 'below 1'];
        yield 'location and coordinates' => [static fn (): DemoHospitalRequest => new DemoHospitalRequest(new NewDemoHospital('A', HospitalTier::BASIC, 100, 1, latitude: 50.0, longitude: 8.0), null, 1000, 12, 0.05, 'somewhere'), 'not both'];
    }
}
