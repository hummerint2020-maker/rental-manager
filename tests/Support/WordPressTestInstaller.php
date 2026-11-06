<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support;

use mysqli;
use RuntimeException;
use ZipArchive;

final class WordPressTestInstaller
{
    public static function assertDisposable(string $database): void
    {
        if (!preg_match('/^rm_test_[a-z0-9_]+$/D', $database) || getenv('RM_ALLOW_TEST_DB_RESET') !== '1') {
            throw new RuntimeException(
                'Use an rm_test_* database and set RM_ALLOW_TEST_DB_RESET=1. Never use hosting data.'
            );
        }
    }

    public static function install(
        string $database,
        string $user,
        string $password,
        string $host,
        string $version
    ): void {
        self::assertDisposable($database);
        if (!preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $version)) {
            throw new RuntimeException('Use an explicit released WordPress version.');
        }
        $core = getenv('WP_CORE_DIR') ?: sys_get_temp_dir() . '/rm-wp-core-' . $version;
        $tests = getenv('WP_TESTS_DIR') ?: sys_get_temp_dir() . '/rm-wp-tests-' . $version;
        foreach ([$core, $tests] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            } elseif (file_exists($directory . '/wp-config.php') || file_exists($directory . '/wp-tests-config.php')) {
                throw new RuntimeException('Refusing to overwrite an existing WordPress configuration: ' . $directory);
            }
        }
        self::download('https://wordpress.org/wordpress-' . $version . '.zip', 'wordpress/', $core);
        $testVersion = substr_count($version, '.') === 1 ? $version . '.0' : $version;
        self::download(
            'https://codeload.github.com/WordPress/wordpress-develop/zip/refs/tags/' . $testVersion,
            'wordpress-develop-' . $testVersion . '/tests/phpunit/',
            $tests,
            ['includes/', 'data/']
        );
        $parts = parse_url('mysql://' . $host);
        if ($parts === false || !isset($parts['host'])) {
            throw new RuntimeException('Invalid database host.');
        }
        $connection = new mysqli($parts['host'], $user, $password, '', $parts['port'] ?? 3306);
        $connection->query('CREATE DATABASE IF NOT EXISTS `' . $database
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci');
        $connection->close();
        $values = [
            'ABSPATH' => rtrim(str_replace('\\', '/', $core), '/') . '/', 'DB_NAME' => $database,
            'DB_USER' => $user, 'DB_PASSWORD' => $password, 'DB_HOST' => $host,
            'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => 'utf8mb4_unicode_520_ci', 'WP_TESTS_DOMAIN' => 'example.test',
            'WP_TESTS_EMAIL' => 'admin@example.test', 'WP_TESTS_TITLE' => 'Rental Manager tests',
            'WP_PHP_BINARY' => PHP_BINARY, 'WP_DEBUG' => true,
        ];
        $config = "<?php\n";
        foreach ($values as $name => $value) {
            $config .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }
        $config .= "if (!defined('DISABLE_WP_CRON')) { define('DISABLE_WP_CRON', true); }\n";
        $config .= '$table_prefix = "wptests_";' . "\n";
        file_put_contents($tests . '/wp-tests-config.php', $config);
        chmod($tests . '/wp-tests-config.php', 0600);
        echo 'Installed official WordPress core and test-library files in ' . $tests . ".\n";
    }

    /** @param list<string> $allowed */
    private static function download(string $url, string $prefix, string $destination, array $allowed = []): void
    {
        $archive = tempnam(sys_get_temp_dir(), 'rm-wp-');
        if ($archive === false) {
            throw new RuntimeException('Cannot allocate download file.');
        }
        $zip = new ZipArchive();
        try {
            if (!copy($url, $archive) || $zip->open($archive) !== true) {
                throw new RuntimeException('Unable to download official WordPress archive: ' . $url);
            }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if ($name === false || !str_starts_with($name, $prefix)) {
                    continue;
                }
                $relative = substr($name, strlen($prefix));
                if ($relative === '' || str_ends_with($relative, '/')) {
                    continue;
                }
                if (str_contains($relative, '..') || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
                    throw new RuntimeException('Unsafe archive entry.');
                }
                if ($allowed !== [] && !array_filter($allowed, static fn ($p) => str_starts_with($relative, $p))) {
                    continue;
                }
                $target = $destination . '/' . $relative;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                $content = $zip->getFromIndex($i);
                if ($content === false || file_put_contents($target, $content) === false) {
                    throw new RuntimeException('Cannot extract WordPress test file.');
                }
            }
            $zip->close();
        } finally {
            unlink($archive);
        }
    }
}
