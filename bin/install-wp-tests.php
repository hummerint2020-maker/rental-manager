<?php

declare(strict_types=1);

use RentalManager\Tests\Support\WordPressTestInstaller;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) < 4) {
    fwrite(STDERR, "Usage: bin/install-wp-tests.sh <rm_test_db> <user> <password> [host:port] [wp-version]\n");
    exit(1);
}
WordPressTestInstaller::install(
    $arguments[1],
    $arguments[2],
    $arguments[3],
    $arguments[4] ?? '127.0.0.1:3306',
    $arguments[5] ?? '7.1.4'
);
