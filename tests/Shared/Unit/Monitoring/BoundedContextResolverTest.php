<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Http\BoundedContextResolver;
use PHPUnit\Framework\TestCase;

final class BoundedContextResolverTest extends TestCase
{
    private BoundedContextResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->resolver = new BoundedContextResolver();
    }

    public function testResolveFromControllerReturnsBoundedContext(): void
    {
        self::assertSame(
            'Statistics',
            $this->resolver->resolveFromController('App\\Statistics\\UI\\Http\\Controller\\DashboardController::index'),
        );
    }

    public function testResolveFromControllerReturnsNullForUnknownController(): void
    {
        self::assertNull($this->resolver->resolveFromController('Acme\\Demo\\Controller::index'));
    }
}
