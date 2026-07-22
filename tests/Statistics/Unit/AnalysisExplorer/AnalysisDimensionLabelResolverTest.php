<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionLabelResolver;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
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
        $resus = new AnalysisDimension(
            key: 'resus',
            column: 'requires_resus',
            label: 'Resuscitation required',
            type: AnalysisDimensionType::Boolean,
        );

        self::assertSame('Yes', $resolver->labelFor($resus, 1));
        self::assertSame('No', $resolver->labelFor($resus, 0));
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
