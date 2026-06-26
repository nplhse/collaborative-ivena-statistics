<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Indication;

use App\Allocation\Application\Indication\IndicationMatchSuggestionService;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationMatchSuggestionServiceTest extends KernelTestCase
{
    use Factories;

    public function testSuggestsSameCode(): void
    {
        self::bootKernel();

        IndicationNormalizedFactory::createOne(['code' => 501, 'name' => 'Chest pain']);
        $raw = IndicationRawFactory::createOne(['code' => 501, 'name' => 'chest pain variant']);

        /** @var IndicationMatchSuggestionService $service */
        $service = new IndicationMatchSuggestionService(self::getContainer()->get(IndicationNormalizedRepository::class));
        $suggestions = $service->suggest($raw);

        self::assertNotEmpty($suggestions);
        self::assertSame(501, (int) preg_replace('/\D+/', '', $suggestions[0]['label']));
    }
}
