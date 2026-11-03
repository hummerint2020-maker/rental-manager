<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RentalManager\Kernel\Health\StorageProbe;
use RuntimeException;

final class StorageProbeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rm-health-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        self::assertSame(['.', '..'], scandir($this->directory), 'Probe must clean up every file and directory.');
        rmdir($this->directory);
    }

    public function testOnly403Or404WithoutMarkerLeakagePass(): void
    {
        foreach ([200, 301, 302, 401, 403, 404, 500] as $status) {
            $result = $this->probe($status);
            self::assertSame(in_array($status, [403, 404], true) ? 'pass' : 'fail', $result);
        }
        self::assertSame('fail', $this->probe(403, true));
    }

    public function testBlockedPublicControlCannotProduceFalsePositive(): void
    {
        self::assertSame('fail', $this->probe(403, false, 403));
    }

    public function testTransportFailureCleansUpAndFails(): void
    {
        $request = static function (string $url): array {
            throw new RuntimeException('HTTP unavailable.');
        };
        self::assertSame('fail', (new StorageProbe($this->directory, 'https://host.test', $request))->run()->status);
    }

    public function testUnavailableStorageFailsWithoutCallingHttp(): void
    {
        $called = false;
        $request = static function (string $url) use (&$called): array {
            $called = true;
            return ['status' => 404, 'body' => ''];
        };
        $probe = new StorageProbe($this->directory . '/missing/base', 'https://host.test', $request);
        self::assertSame('fail', $probe->run()->status);
        self::assertFalse($called);
    }

    private function probe(int $status, bool $leak = false, int $controlStatus = 200): string
    {
        $request = function (string $url) use ($status, $leak, $controlStatus): array {
            $path = $this->directory . parse_url($url, PHP_URL_PATH);
            self::assertFileExists($path);
            $control = str_ends_with($url, '/control.txt');
            return [
                'status' => $control ? $controlStatus : $status,
                'body' => $control || $leak ? (string) file_get_contents($path) : 'Denied',
            ];
        };
        return (new StorageProbe($this->directory, 'https://host.test', $request))->run()->status;
    }
}
