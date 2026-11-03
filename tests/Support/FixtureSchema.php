<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support;

use RuntimeException;
use wpdb;

/** Test-only schema: no production migrations or business tables are introduced. */
final class FixtureSchema
{
    public static function table(wpdb $db, string $name): string
    {
        if (
            !preg_match('/^[a-z0-9_]+$/iD', $db->prefix)
            || !in_array($name, ['accounts', 'settings', 'renters', 'memberships', 'probe'], true)
        ) {
            throw new RuntimeException('Invalid fixture table identifier.');
        }
        return $db->prefix . 'rm_test_' . $name;
    }

    public static function create(wpdb $db): void
    {
        $accounts = self::table($db, 'accounts');
        $renters = self::table($db, 'renters');
        $base = 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'account_id BIGINT UNSIGNED NOT NULL, public_id CHAR(26) NOT NULL UNIQUE, '
            . 'UNIQUE KEY account_identity (account_id, id), '
            . 'FOREIGN KEY (account_id) REFERENCES `' . $accounts . '` (id), ';
        $definitions = [
            'accounts' => 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'public_id CHAR(26) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL',
            'settings' => $base . 'timezone VARCHAR(64) NOT NULL, currency CHAR(3) NOT NULL, '
                . 'locale VARCHAR(10) NOT NULL, receipt_prefix VARCHAR(20) NOT NULL, '
                . 'UNIQUE KEY one_per_account (account_id)',
            'renters' => $base . 'full_name VARCHAR(100) NOT NULL, portal_user_id BIGINT UNSIGNED NULL',
            'memberships' => $base . 'user_id BIGINT UNSIGNED NOT NULL, role VARCHAR(20) NOT NULL, '
                . 'status VARCHAR(20) NOT NULL, renter_id BIGINT UNSIGNED NULL, '
                . 'UNIQUE KEY account_user (account_id, user_id), '
                . 'FOREIGN KEY (account_id, renter_id) REFERENCES `' . $renters . '` (account_id, id)',
            'probe' => $base . 'note VARCHAR(100) NOT NULL',
        ];
        foreach ($definitions as $name => $definition) {
            self::query($db, 'CREATE TABLE IF NOT EXISTS `' . self::table($db, $name) . '` (' . $definition
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
    }

    public static function query(wpdb $db, string $sql): void
    {
        if ($db->query($sql) === false) {
            throw new RuntimeException('Fixture database failure: ' . $db->last_error);
        }
    }
}
