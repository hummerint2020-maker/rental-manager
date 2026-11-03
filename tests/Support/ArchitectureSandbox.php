<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ArchitectureSandbox
{
    public readonly string $directory;
    private string $project;

    public function __construct()
    {
        $this->project = dirname(__DIR__, 2);
        $this->directory = $this->project . '/tests/artifacts/architecture-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/src', 0777, true);
        $config = file_get_contents($this->project . '/deptrac.yaml');
        if ($config === false) {
            throw new RuntimeException('Missing architecture configuration.');
        }
        $path = str_replace('\\', '/', $this->directory);
        $config = str_replace('    - ./src', "    - '" . $path . "/src'", $config);
        $config = str_replace('cache_file: .deptrac.cache', "cache_file: '" . $path . "/cache'", $config);
        file_put_contents($this->directory . '/deptrac.yaml', $config);
    }

    public function writeClass(string $name, ?string $dependency = null, bool $interface = false): void
    {
        $position = strrpos($name, '\\');
        if ($position === false || !str_starts_with($name, 'RentalManager\\')) {
            throw new RuntimeException('Fixture must use the project namespace.');
        }
        $namespace = substr($name, 0, $position);
        $short = substr($name, $position + 1);
        $body = $dependency === null ? '' : 'public function call(\\' . $dependency . ' $value): void {}';
        $source = '<?php namespace ' . $namespace . '; ' . ($interface ? 'interface ' : 'final class ')
            . $short . ' { ' . $body . ' }';
        $relative = str_replace('\\', '/', substr($name, strlen('RentalManager\\'))) . '.php';
        $target = $this->directory . '/src/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, $source);
    }

    /**
     * @return array{status: int, report: array{Report: array<string, int>, files: array<string, mixed>}}
     */
    public function deptrac(): array
    {
        $reportPath = $this->directory . '/report.json';
        [$status, $output] = $this->run([
            PHP_BINARY, $this->project . '/vendor/bin/deptrac', 'analyse',
            '--config-file=' . $this->directory . '/deptrac.yaml', '--no-progress',
            '--formatter=json', '--output=' . $reportPath, '--fail-on-uncovered',
        ]);
        if (!is_file($reportPath)) {
            throw new RuntimeException('Deptrac did not produce a report: ' . $output);
        }
        return [
            'status' => $status,
            'report' => json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /** @param list<string> $command
     *  @return array{int, string}
     */
    public function run(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->project);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start architecture checker.');
        }
        $output = stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    public function remove(): void
    {
        $directory = realpath($this->directory);
        $root = realpath($this->project . '/tests/artifacts');
        if (
            $directory === false || $root === false
            || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR . 'architecture-')
        ) {
            throw new RuntimeException('Refusing to delete a path outside the generated architecture sandbox.');
        }
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            ) as $file
        ) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
