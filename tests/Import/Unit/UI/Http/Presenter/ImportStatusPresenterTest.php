<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\UI\Http\Presenter;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\UI\Http\Presenter\ImportStatusPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ImportStatusPresenterTest extends TestCase
{
    #[DataProvider('stepStateProvider')]
    public function testBuildsExpectedStepStates(ImportStatus $status, string $activeStepKey, string $stepsModifier): void
    {
        $import = new Import()
            ->setStatus($status)
            ->setRowCount(100);

        $importReflection = new \ReflectionProperty(Import::class, 'id');
        $importReflection->setValue($import, 42);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/import/42');

        $presenter = new ImportStatusPresenter($translator, $urlGenerator);
        $view = $presenter->present($import);

        self::assertSame($stepsModifier, $view['stepsModifier']);
        self::assertCount(3, $view['steps']);
        self::assertSame('done', $view['steps'][0]['state']);

        $activeSteps = array_filter($view['steps'], static fn (array $step): bool => 'active' === $step['state']);
        self::assertCount(1, $activeSteps);
        self::assertSame($activeStepKey, array_values($activeSteps)[0]['key']);
    }

    /**
     * @return array<string, array{status: ImportStatus, activeStepKey: string, stepsModifier: string}>
     */
    public static function stepStateProvider(): array
    {
        return [
            'PENDING' => ['status' => ImportStatus::PENDING, 'activeStepKey' => 'processing', 'stepsModifier' => 'green'],
            'RUNNING' => ['status' => ImportStatus::RUNNING, 'activeStepKey' => 'processing', 'stepsModifier' => 'green'],
            'COMPLETED' => ['status' => ImportStatus::COMPLETED, 'activeStepKey' => 'result', 'stepsModifier' => 'green'],
            'PARTIAL' => ['status' => ImportStatus::PARTIAL, 'activeStepKey' => 'result', 'stepsModifier' => 'yellow'],
            'FAILED' => ['status' => ImportStatus::FAILED, 'activeStepKey' => 'result', 'stepsModifier' => 'red'],
        ];
    }
}
