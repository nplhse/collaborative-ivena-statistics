<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Allows inline `style=""` attributes in every Content Security Policy that NelmioSecurityBundle sends (ADR 047).
 *
 * Nelmio 3.9 does not know the directive `style-src-attr`, so it is appended here, after Nelmio's listener (priority 0)
 * has set the headers. Without it, the nonces that EasyAdmin adds to `style-src` switch off `'unsafe-inline'` there,
 * and every style attribute on `/admin` is reported (#580). Styles in `<style>` elements stay under `style-src`.
 *
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -16)]
final readonly class StyleAttributePolicyListener
{
    public const string DIRECTIVE = "style-src-attr 'unsafe-inline'";

    private const array HEADERS = ['Content-Security-Policy', 'Content-Security-Policy-Report-Only'];

    public function __invoke(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name) {
            $policy = $headers->get($name);
            if (null === $policy || '' === $policy || str_contains($policy, 'style-src-attr')) {
                continue;
            }

            $headers->set($name, rtrim($policy, '; ').'; '.self::DIRECTIVE);
        }
    }
}
