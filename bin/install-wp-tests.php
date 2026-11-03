<?php

declare(strict_types=1);

use RentalManager\Tests\Support\WordPressTestInstaller;

require dirname(__DIR__) . '/vendor/autoload.php';
if ($argc < 4) {
    fwrite(STDERR, "Usage: bin/install-wp-tests.sh <rm_test_db> <user> <password> [host:port] [wp-version]\n");
    exit(1);
}
WordPressTestInstaller::install($argv[1], $argv[2], $argv[3], $argv[4] ?? '127.0.0.1:3306', $argv[5] ?? '6.4');
