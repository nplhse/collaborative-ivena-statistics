<?php

declare(strict_types=1);

namespace App\Analytics\Application\RequestTracking;

use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;

final class UserAgentNormalizer
{
    /**
     * @return array{browserFamily: BrowserFamily, deviceType: DeviceType}
     */
    public function normalize(?string $userAgent): array
    {
        $ua = $userAgent ?? '';

        return [
            'browserFamily' => $this->resolveBrowserFamily($ua),
            'deviceType' => $this->resolveDeviceType($ua),
        ];
    }

    private function resolveBrowserFamily(string $ua): BrowserFamily
    {
        if ('' === $ua) {
            return BrowserFamily::Other;
        }

        if (preg_match('/Edg(?:e|A|iOS)?\//i', $ua)) {
            return BrowserFamily::Edge;
        }

        if (preg_match('/Chrome\//i', $ua) && !preg_match('/Chromium\//i', $ua)) {
            return BrowserFamily::Chrome;
        }

        if (preg_match('/Firefox\//i', $ua)) {
            return BrowserFamily::Firefox;
        }

        if (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome\//i', $ua)) {
            return BrowserFamily::Safari;
        }

        return BrowserFamily::Other;
    }

    private function resolveDeviceType(string $ua): DeviceType
    {
        if ('' === $ua) {
            return DeviceType::Desktop;
        }

        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview/i', $ua)) {
            return DeviceType::Bot;
        }

        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua)) {
            return DeviceType::Tablet;
        }

        if (preg_match('/Mobi|Android.*Mobile|iPhone|iPod|Windows Phone/i', $ua)) {
            return DeviceType::Mobile;
        }

        if (preg_match('/Android/i', $ua)) {
            return DeviceType::Tablet;
        }

        return DeviceType::Desktop;
    }
}
