<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

use RuntimeException;
use Throwable;
use wpdb;

final class Checks
{
    /** @return list<Result> */
    public static function information(string $server, string $wordpress): array
    {
        return [
            new Result('web_server', 'info', 'Web server software', $server),
            self::result('wordpress', version_compare($wordpress, '7.0', '>='), 'WordPress version', $wordpress),
        ];
    }

    /** @return list<Result> */
    public static function currentInformation(): array
    {
        return self::information(
            (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
            (string) ($GLOBALS['wp_version'] ?? '')
        );
    }

    public static function memorySufficient(string $limit): bool
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return true;
        }
        if (!preg_match('/^(\d+)\s*([KMG]?)B?$/iD', $limit, $match)) {
            return false;
        }
        $power = array_search(strtoupper($match[2]), ['', 'K', 'M', 'G'], true);
        return $power !== false && (int) $match[1] * (1024 ** $power) >= 256 * 1024 * 1024;
    }

    public static function databaseSupported(string $version): bool
    {
        if (stripos($version, 'MariaDB') !== false) {
            return preg_match('/(\d+\.\d+(?:\.\d+)?)-MariaDB/i', $version, $match) === 1
                && version_compare($match[1], '10.6', '>=');
        }
        return preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $version, $match) === 1
            && version_compare($match[1], '5.7.44', '>=');
    }

    /** @return list<Result> */
    public static function databaseInformation(string $version): array
    {
        $results = [self::result(
            'database',
            self::databaseSupported($version),
            'MySQL 5.7.44 or MariaDB 10.6 or later',
            $version
        )];
        if (stripos($version, 'MariaDB') === false && preg_match('/^5\.7\./', $version)) {
            $results[] = new Result('database_eol', 'warn', 'MySQL 5.7 is end-of-life upstream', $version);
        }
        return $results;
    }

    public static function result(string $id, bool $passed, string $message, string $detail = ''): Result
    {
        return new Result($id, $passed ? 'pass' : 'fail', $message, $detail);
    }

    /** @return list<Result> */
    public function run(): array
    {
        $images = [];
        foreach (['gd' => 'GD', 'imagick' => 'Imagick'] as $extension => $label) {
            if (extension_loaded($extension)) {
                $images[] = $label;
            }
        }
        $results = array_merge(self::currentInformation(), [
            self::result('php', version_compare(PHP_VERSION, '8.1', '>='), 'PHP 8.1 or later', PHP_VERSION),
            self::result('sodium', extension_loaded('sodium'), 'Sodium extension'),
            self::result('mbstring', extension_loaded('mbstring'), 'Mbstring extension'),
            self::result(
                'images',
                $images !== [],
                'GD or Imagick extension',
                implode(', ', $images)
            ),
            self::result(
                'memory',
                self::memorySufficient((string) ini_get('memory_limit')),
                'Memory limit at least 256 MB',
                (string) ini_get('memory_limit')
            ),
            self::result(
                'cron',
                defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true,
                'WP-Cron disabled for system cron'
            ),
        ]);
        try {
            // DDL and transactions use a separate connection, never the caller's transaction.
            $db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $db->set_prefix($GLOBALS['wpdb']->prefix);
            try {
                array_push($results, ...(new DatabaseProbe($db))->run());
            } finally {
                $db->close();
            }
        } catch (Throwable $error) {
            foreach (['database', 'engine', 'foreign_keys', 'rollback'] as $id) {
                $results[] = self::result($id, false, 'Database diagnostic could not complete');
            }
        }
        $uploads = wp_upload_dir(null, false);
        if ($uploads['error'] || !wp_mkdir_p($uploads['basedir'])) {
            $results[] = self::result('private_storage', false, 'Private storage HTTP canary');
        } else {
            $request = static function (string $url): array {
                $response = wp_remote_get($url, [
                    'timeout' => 5,
                    'redirection' => 0,
                    'limit_response_size' => 4096,
                    'headers' => ['Cache-Control' => 'no-cache'],
                ]);
                if (is_wp_error($response)) {
                    throw new RuntimeException('Loopback failed.');
                }
                return [
                    'status' => wp_remote_retrieve_response_code($response),
                    'body' => wp_remote_retrieve_body($response),
                ];
            };
            $results[] = (new StorageProbe($uploads['basedir'], $uploads['baseurl'], $request))->run();
        }
        try {
            $results[] = (new SchedulerProbe())->run();
        } catch (Throwable $error) {
            $results[] = self::result('scheduler', false, 'Action Scheduler canary execution');
        }
        return $results;
    }
}
