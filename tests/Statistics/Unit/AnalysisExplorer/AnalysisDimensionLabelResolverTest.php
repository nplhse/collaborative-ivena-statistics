<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionLabelResolver;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalysisDimensionLabelResolverTest extends TestCase
{
    public function testTranslatesGenderBucketViaTranslationKey(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => match ($id) {
                AllocationStatsGenderProjectionCode::Male->labelTranslationKey() => 'Male',
                default => $id,
            },
        );

        $resolver = $this->resolver($translator);
        $gender = new DimensionRegistry()->get('gender');

        self::assertSame('Male', $resolver->labelFor($gender, AllocationStatsGenderProjectionCode::Male->value));
    }

    public function testBooleanDimensionUsesYesNoTranslations(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => match ($id) {
                'action.yes' => 'Yes',
                'action.no' => 'No',
                default => $id,
            },
        );

        $resolver = $this->resolver($translator);
        $resus = new DimensionRegistry()->get('resus');

        self::assertSame('Yes', $resolver->labelFor($resus, 1));
        self::assertSame('No', $resolver->labelFor($resus, 0));
    }

    public function testWeekdayUsesTranslationKeys(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => match ($id) {
                'stats.indication.weekday.1' => 'Montag',
                default => $id,
            },
        );

        $resolver = $this->resolver($translator);
        $weekday = new DimensionRegistry()->get('weekday');

        self::assertSame('Montag', $resolver->labelFor($weekday, 1));
    }

    public function testAgeGroupUnknownUsesTranslationKey(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => match ($id) {
                'stats.benchmark.age_group.unknown' => 'Unbekannt',
                default => $id,
            },
        );

        $resolver = $this->resolver($translator);
        $ageGroup = new DimensionRegistry()->get('age_group');

        self::assertSame('Unbekannt', $resolver->labelFor($ageGroup, 'unknown'));
        self::assertSame('0–18', $resolver->labelFor($ageGroup, '0_18'));
    }

    public function testNullBucketUsesUnknownTranslation(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => match ($id) {
                'stats.analysis_explorer.bucket.unknown' => 'Unbekannt',
                default => $id,
            },
        );

        $resolver = $this->resolver($translator);
        $month = new DimensionRegistry()->get('month');

        self::assertSame('Unbekannt', $resolver->labelFor($month, '__null__'));
    }

    public function testMonthUsesTranslatorLocale(): void
    {
        $translatorDe = $this->createStub(TranslatorInterface::class);
        $translatorDe->method('getLocale')->willReturn('de');

        $translatorEn = $this->createStub(TranslatorInterface::class);
        $translatorEn->method('getLocale')->willReturn('en');

        $month = new DimensionRegistry()->get('month');
        $deLabel = $this->resolver($translatorDe)->labelFor($month, 3);
        $enLabel = $this->resolver($translatorEn)->labelFor($month, 3);

        self::assertNotSame('', $deLabel);
        self::assertNotSame('', $enLabel);
        self::assertNotSame($enLabel, $deLabel);
    }

    private function resolver(TranslatorInterface $translator): AnalysisDimensionLabelResolver
    {
        $entityLabelResolver = $this->createStub(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        return new AnalysisDimensionLabelResolver(
            $translator,
            $entityLabelResolver,
            new HospitalCohortLabelResolver($translator),
        );
    }
}
