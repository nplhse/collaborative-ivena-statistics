<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\RequestTracking;

use App\Analytics\Application\RequestTracking\UserAgentNormalizer;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAgentNormalizerTest extends TestCase
{
    private UserAgentNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new UserAgentNormalizer();
    }

    #[DataProvider('provideUserAgents')]
    public function testNormalize(?string $ua, BrowserFamily $browser, DeviceType $device): void
    {
        $result = $this->normalizer->normalize($ua);

        self::assertSame($browser, $result['browserFamily']);
        self::assertSame($device, $result['deviceType']);
    }

    /**
     * @return iterable<string, array{0: ?string, 1: BrowserFamily, 2: DeviceType}>
     */
    public static function provideUserAgents(): iterable
    {
        yield 'empty' => [null, BrowserFamily::Other, DeviceType::Desktop];
        yield 'chrome desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            BrowserFamily::Chrome,
            DeviceType::Desktop,
        ];
        yield 'firefox' => [
            'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
            BrowserFamily::Firefox,
            DeviceType::Desktop,
        ];
        yield 'safari' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            BrowserFamily::Safari,
            DeviceType::Desktop,
        ];
        yield 'edge' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            BrowserFamily::Edge,
            DeviceType::Desktop,
        ];
        yield 'iphone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            BrowserFamily::Safari,
            DeviceType::Mobile,
        ];
        yield 'bot' => [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            BrowserFamily::Other,
            DeviceType::Bot,
        ];
    }
}
