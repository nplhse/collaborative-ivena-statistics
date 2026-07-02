<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\Service;

use App\Admin\Application\Service\ApplicationCodeSizeCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ApplicationCodeSizeCalculatorTest extends TestCase
{
    public function testCalculatesBytesExcludingVolatileDirectories(): void
    {
        $projectDir = sys_get_temp_dir().'/app-code-size-'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/src', 0777, true);
        mkdir($projectDir.'/vendor/pkg', 0777, true);
        mkdir($projectDir.'/var/cache', 0777, true);
        mkdir($projectDir.'/node_modules/pkg', 0777, true);

        file_put_contents($projectDir.'/src/App.php', '<?php echo 1;');
        file_put_contents($projectDir.'/vendor/pkg/lib.php', str_repeat('a', 100));
        file_put_contents($projectDir.'/var/cache/warm.php', str_repeat('b', 500));
        file_put_contents($projectDir.'/node_modules/pkg/index.js', str_repeat('c', 200));

        try {
            $calculator = new ApplicationCodeSizeCalculator(
                new ArrayAdapter(),
                $projectDir,
            );

            self::assertSame(
                strlen('<?php echo 1;') + 100,
                $calculator->getBytes(),
            );
        } finally {
            $this->removeDirectory($projectDir);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
