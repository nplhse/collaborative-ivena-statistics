<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern\Package;

use App\DataFixtures\Pattern\Application\DefaultPatternPackage;
use App\DataFixtures\Pattern\Package\PatternPackageHasher;
use App\DataFixtures\Pattern\Package\PatternPackageReader;
use App\DataFixtures\Pattern\Package\PatternPackageWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validation;

/**
 * Reader and writer without the kernel; packages are synthetic (the code defaults), never real data.
 */
abstract class PatternPackageTestCase extends TestCase
{
    protected function reader(): PatternPackageReader
    {
        return new PatternPackageReader(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }

    protected function writer(): PatternPackageWriter
    {
        return new PatternPackageWriter(new PatternPackageHasher(), new Filesystem());
    }

    /**
     * The sealed code defaults as decoded JSON, ready to be changed by a test.
     *
     * @return array<string, mixed>
     */
    protected function defaultsData(): array
    {
        $json = $this->writer()->encode($this->writer()->seal(DefaultPatternPackage::build(new \DateTimeImmutable('2026-10-01T00:00:00+00:00'))));
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Encodes changed data with a fresh checksum, so only the change itself is wrong.
     *
     * @param array<string, mixed> $data
     */
    protected function encode(array $data, bool $reseal = true): string
    {
        if ($reseal) {
            $content = array_diff_key($data, ['meta' => true]);
            /** @var array{checksum: array{value: string}} $meta */
            $meta = $data['meta'];
            $meta['checksum']['value'] = hash('sha256', json_encode($content, PatternPackageHasher::JSON_FLAGS));
            $data['meta'] = $meta;
        }

        return json_encode($data, PatternPackageHasher::JSON_FLAGS);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string> path → message
     */
    protected function violations(array $data, bool $reseal = true): array
    {
        $result = $this->reader()->readJson($this->encode($data, $reseal));
        self::assertFalse($result->isValid(), 'the package is refused');

        $violations = [];
        foreach ($result->violations as $violation) {
            $violations[$violation->path] = $violation->message;
        }

        return $violations;
    }
}
