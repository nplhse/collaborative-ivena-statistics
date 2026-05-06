<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final class StatisticsFilterValueResolverTest extends KernelTestCase
{
    public function testResolvesStatisticsFilter(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsFilterValueResolver::class);

        $request = new Request(query: ['scope' => 'public']);
        $argument = new ArgumentMetadata('filter', StatisticsFilter::class, false, false, null);
        $resolved = iterator_to_array($resolver->resolve($request, $argument), false);

        self::assertCount(1, $resolved);
        self::assertSame('public', $resolved[0]->scope->value);
    }

    public function testIgnoresOtherArgumentTypes(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsFilterValueResolver::class);

        $resolved = iterator_to_array(
            $resolver->resolve(new Request(), new ArgumentMetadata('foo', 'string', false, false, null)),
            false,
        );

        self::assertSame([], $resolved);
    }
}
