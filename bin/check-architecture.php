<?php

declare(strict_types=1);

use RentalManager\Architecture\BoundaryInspector;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$source = $argv[1] ?? $root . '/src';
$inspector = new BoundaryInspector($root . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php');
$errors = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $relative = 'src/' . substr($file->getPathname(), strlen($source) + 1);
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            throw new RuntimeException('Unable to read ' . $file->getPathname());
        }
        $errors = array_merge($errors, $inspector->inspect($content, $relative));
    }
}
foreach ($errors as $error) {
    fwrite(STDERR, $error . PHP_EOL);
}
if ($errors !== []) {
    exit(1);
}
echo "AST architecture boundaries passed.\n";
