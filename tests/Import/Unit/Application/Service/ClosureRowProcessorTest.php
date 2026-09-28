<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Exception\RowRejectException;
use App\Import\Application\Service\ClosureRowProcessor;
use App\Import\Domain\Entity\Import;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClosureRowProcessorTest extends KernelTestCase
{
    public function testBlankRowIsRejectedBeforeItIsStored(): void
    {
        self::bootKernel();
        $processor = self::getContainer()->get(ClosureRowProcessor::class);

        try {
            $processor->process([], new Import());
            self::fail('A blank closure row must be rejected.');
        } catch (RowRejectException $exception) {
            self::assertNotEmpty($exception->messages());
            self::assertStringContainsString('hospitalShortName', $exception->messages()[0]);
        }
    }
}
