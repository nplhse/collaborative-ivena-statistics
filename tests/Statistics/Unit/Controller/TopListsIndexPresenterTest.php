<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\TopList\TopListDefinitionInterface;
use App\Statistics\Application\TopList\TopListDefinitionRegistry;
use App\Statistics\UI\Http\Controller\TopListsIndexPresenter;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class TopListsIndexPresenterTest extends TestCase
{
    public function testBuildsCardUrlsForShowRoute(): void
    {
        $diagnoses = $this->createStub(TopListDefinitionInterface::class);
        $diagnoses->method('key')->willReturn('top_diagnoses');
        $diagnoses->method('labelTranslationKey')->willReturn('stats.top_lists.top_diagnoses.label');
        $diagnoses->method('descriptionTranslationKey')->willReturn('stats.top_lists.top_diagnoses.description');
        $diagnoses->method('icon')->willReturn('tabler:id');

        $registry = new TopListDefinitionRegistry([$diagnoses]);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );

        $index = new TopListsIndexPresenter(
            $registry,
            new StatisticsNavigationUrlBuilder($router),
        )->present(new Request(query: ['scope' => 'public']));

        self::assertCount(1, $index->cards);
        self::assertSame('top_diagnoses', $index->cards[0]->key);
        self::assertSame('tabler:id', $index->cards[0]->icon);
        self::assertStringContainsString('app_stats_top_lists_show', $index->cards[0]->url);
        self::assertStringContainsString('report=top_diagnoses', $index->cards[0]->url);
    }
}
