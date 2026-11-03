<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    private string $pluginDirectory;

    protected function setUp(): void
    {
        $this->pluginDirectory = sys_get_temp_dir() . '/rm-bootstrap-' . bin2hex(random_bytes(8));
        mkdir($this->pluginDirectory);
        copy(dirname(__DIR__, 2) . '/rental-manager.php', $this->pluginDirectory . '/rental-manager.php');
    }

    protected function tearDown(): void
    {
        $scheduler = $this->pluginDirectory . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
        if (is_file($scheduler)) {
            unlink($scheduler);
            rmdir(dirname($scheduler));
            rmdir(dirname($scheduler, 2));
        }
        foreach (['vendor', 'vendor-prefixed'] as $directory) {
            $autoload = $this->pluginDirectory . '/' . $directory . '/autoload.php';
            if (is_file($autoload)) {
                unlink($autoload);
                rmdir(dirname($autoload));
            }
        }
        unlink($this->pluginDirectory . '/rental-manager.php');
        rmdir($this->pluginDirectory);
    }

    /** @return list<array{string}> */
    public static function unsupportedWordPressVersions(): array
    {
        return [['5.9'], ['6.0'], ['6.4'], ['6.9.99'], ['7.0-beta1'], ['']];
    }

    #[DataProvider('unsupportedWordPressVersions')]
    public function testUnsupportedWordPressDoesNotLoadDependencies(string $version): void
    {
        $this->installAutoloaders(true);
        $result = $this->probe($version);
        self::assertFalse($result['booted']);
        self::assertStringContainsString('requires PHP 8.1', $result['notice']);
    }

    public function testMissingDependenciesProduceAnAdminNotice(): void
    {
        $result = $this->probe();
        self::assertFalse($result['booted']);
        self::assertStringContainsString('missing its dependencies', $result['notice']);
    }

    public function testMissingPrefixedDependenciesDoNotLoadComposer(): void
    {
        mkdir($this->pluginDirectory . '/vendor');
        file_put_contents(
            $this->pluginDirectory . '/vendor/autoload.php',
            '<?php throw new RuntimeException("Loaded");'
        );
        $result = $this->probe();
        self::assertFalse($result['booted']);
        self::assertStringContainsString('missing its dependencies', $result['notice']);
    }

    public function testNoticesAreHiddenFromNonAdministrators(): void
    {
        $result = $this->probe('6.3', 'renter');
        self::assertFalse($result['booted']);
        self::assertSame('', $result['notice']);
    }

    public function testDirectAccessDoesNothing(): void
    {
        $this->installAutoloaders(true);
        $result = $this->probe('7.1.4', 'admin', 'direct');
        self::assertFalse($result['booted']);
        self::assertSame('', $result['notice']);
        self::assertSame(0, $result['scheduler_loads']);
    }

    /** @return list<array{string}> */
    public static function supportedWordPressVersions(): array
    {
        return [['7.0'], ['7.0.0'], ['7.1.4']];
    }

    #[DataProvider('supportedWordPressVersions')]
    public function testSupportedWordPressBootsTheKernel(string $version): void
    {
        $this->installAutoloaders();
        $this->installScheduler();
        $result = $this->probe($version);
        self::assertTrue($result['booted']);
        self::assertSame('', $result['notice']);
        self::assertSame(1, $result['scheduler_loads']);
    }

    public function testSchedulerLoadsOnlyOnceBeforePluginsLoaded(): void
    {
        $this->installAutoloaders();
        $this->installScheduler();
        $result = $this->probe('7.1.4', 'admin', 'repeat');
        self::assertTrue($result['booted']);
        self::assertSame(1, $result['scheduler_loads']);
    }

    public function testMissingSchedulerPreventsBoot(): void
    {
        $this->installAutoloaders();
        $result = $this->probe();
        self::assertFalse($result['booted']);
        self::assertStringContainsString('missing its dependencies', $result['notice']);
    }

    #[DataProvider('unsupportedWordPressVersions')]
    public function testActivationRejectsUnsupportedWordPress(string $version): void
    {
        $this->installAutoloaders(true);
        $this->installScheduler();
        $result = $this->probe($version, 'admin', 'activate');
        self::assertFalse($result['booted']);
        self::assertTrue($result['deactivated']);
        self::assertSame(0, $result['scheduler_loads']);
        self::assertSame(
            'Rental Manager requires PHP 8.1 or later and WordPress 7.0 or later.',
            $result['die_message']
        );
    }

    public function testActivationRejectsMissingDependencies(): void
    {
        $result = $this->probe('7.1.4', 'admin', 'activate');
        self::assertTrue($result['deactivated']);
        self::assertStringContainsString('missing its dependencies', (string) $result['die_message']);
    }

    public function testActivationAcceptsSupportedInstallation(): void
    {
        $this->installAutoloaders();
        $this->installScheduler();
        $result = $this->probe('7.1.4', 'admin', 'activate');
        self::assertTrue($result['booted']);
        self::assertFalse($result['deactivated']);
        self::assertNull($result['die_message']);
    }

    private function installScheduler(): void
    {
        $directory = $this->pluginDirectory . '/vendor/woocommerce/action-scheduler';
        mkdir($directory, 0777, true);
        file_put_contents(
            $directory . '/action-scheduler.php',
            '<?php if ($GLOBALS["plugins_loaded"]) { throw new RuntimeException("Scheduler loaded too late"); }'
            . ' $GLOBALS["scheduler_loads"]++;'
        );
    }

    private function installAutoloaders(bool $throw = false): void
    {
        foreach (['vendor', 'vendor-prefixed'] as $directory) {
            mkdir($this->pluginDirectory . '/' . $directory);
            $source = $throw ? '<?php throw new RuntimeException("Autoloader must not run");' : '<?php';
            if (!$throw && $directory === 'vendor-prefixed') {
                $source .= ' namespace RentalManager\\Kernel; final class Kernel {'
                    . ' public static function boot(): void {'
                    . ' if ($GLOBALS["scheduler_loads"] !== 1) { throw new \\RuntimeException("Scheduler missing"); }'
                    . ' $GLOBALS["booted"] = true; } }';
            }
            file_put_contents($this->pluginDirectory . '/' . $directory . '/autoload.php', $source);
        }
    }

    /**
     * @return array{booted: bool, notice: string, scheduler_loads: int, deactivated: bool, die_message: ?string}
     */
    private function probe(string $version = '7.1.4', string $role = 'admin', string $mode = 'wordpress'): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__) . '/Support/bootstrap-probe.php',
                $this->pluginDirectory,
                $version,
                $role,
                $mode,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
