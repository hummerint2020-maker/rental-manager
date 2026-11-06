<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PackageCheckTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rm-package-' . bin2hex(random_bytes(12));
        foreach (
            [
                'rental-manager.php', 'build/manifest.json', 'vendor-prefixed/autoload.php',
                'vendor/woocommerce/action-scheduler/action-scheduler.php', 'assets/fonts/OFL.txt',
                'assets/fonts/IBMPlexSansArabic-Regular.ttf', 'assets/fonts/IBMPlexSansArabic-Bold.ttf',
            ] as $file
        ) {
            $this->write($file, 'fixture');
        }
        file_put_contents($this->directory . '/rental-manager.zip', 'fixture');
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testAcceptsSlimRuntimeAndRejectsBundledFontsOrCjkData(): void
    {
        self::assertSame(0, $this->check());
        foreach (['ttfonts/DejaVuSans.ttf', 'data/CJKdata.php', 'data/font/chelvetica.php'] as $unused) {
            $file = 'vendor-prefixed/mpdf/mpdf/' . $unused;
            $this->write($file, 'unexpected');
            self::assertNotSame(0, $this->check());
            unlink($this->directory . '/rental-manager/' . $file);
        }
    }

    public function testRejectsEightMbArchiveAndExtractedRuntimeIndependently(): void
    {
        file_put_contents($this->directory . '/rental-manager.zip', str_repeat('x', 8_000_000));
        self::assertNotSame(0, $this->check());
        file_put_contents($this->directory . '/rental-manager.zip', 'fixture');
        $this->write('oversized.bin', str_repeat('x', 8_000_000));
        self::assertNotSame(0, $this->check());
    }

    public function testRejectsMissingFontsAndActionScheduler(): void
    {
        unlink($this->directory . '/rental-manager/assets/fonts/IBMPlexSansArabic-Bold.ttf');
        self::assertNotSame(0, $this->check());
        $this->write('assets/fonts/IBMPlexSansArabic-Bold.ttf', 'fixture');
        unlink($this->directory . '/rental-manager/vendor/woocommerce/action-scheduler/action-scheduler.php');
        self::assertNotSame(0, $this->check());
    }

    private function write(string $file, string $body): void
    {
        $path = $this->directory . '/rental-manager/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $body);
    }

    private function check(): int
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-package.php', $this->directory],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process);
    }
}
