<?php

declare(strict_types=1);

use RentalManager\Architecture\SqlCompatibilityInspector;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$arguments = $_SERVER['argv'] ?? [];
$directories = count($arguments) > 1 ? array_slice($arguments, 1) : [
    $root . '/src', $root . '/migrations', $root . '/bin',
    $root . '/tests/Support', $root . '/tests/Integration', $root . '/tests/Api',
];
$inspector = new SqlCompatibilityInspector();
$errors = [];
foreach ($directories as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($files as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'sql'], true)) {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            throw new RuntimeException('Unable to read SQL source: ' . $file->getPathname());
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if ($file->getExtension() === 'php') {
            array_push($errors, ...$inspector->inspectPhp($source, $path));
        } else {
            foreach ($inspector->violations($source) as $feature) {
                $errors[] = $path . ': MySQL 5.7 forbids ' . $feature . '.';
            }
        }
    }
}
foreach ($errors as $error) {
    fwrite(STDERR, $error . PHP_EOL);
}
if ($errors !== []) {
    exit(1);
}
echo "MySQL 5.7 SQL compatibility passed.\n";
