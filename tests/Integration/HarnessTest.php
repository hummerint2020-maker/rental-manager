<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use RentalManager\Kernel\Kernel;
use RentalManager\Tests\Support\DatabaseTestCase;
use RentalManager\Tests\Support\FixtureSchema;
use RentalManager\Tests\Support\World;

final class HarnessTest extends DatabaseTestCase
{
    public function testFixtureTablesUseThePortableCollationAndThePinnedProductionDatabase(): void
    {
        $version = (string) $this->db->get_var('SELECT VERSION()');
        $expected = getenv('RM_EXPECT_DB_VERSION');
        if ($expected !== false && $expected !== '') {
            self::assertSame($expected, explode('-', $version)[0]);
        }
        foreach (['accounts', 'settings', 'renters', 'memberships', 'probe'] as $name) {
            $table = FixtureSchema::table($this->db, $name);
            $collation = $this->db->get_var($this->db->prepare(
                'SELECT TABLE_COLLATION FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            self::assertSame('utf8mb4_unicode_520_ci', $collation, $name);
        }
    }

    public function testWorldHasTwoIndependentAccountsAndAllRoles(): void
    {
        self::assertTrue(class_exists(Kernel::class));
        self::assertTrue(function_exists('as_enqueue_async_action'));
        $world = new World($this->db);
        $accounts = FixtureSchema::table($this->db, 'accounts');
        self::assertSame('2', $this->db->get_var("SELECT COUNT(*) FROM `$accounts`"));
        $memberships = FixtureSchema::table($this->db, 'memberships');
        self::assertSame('6', $this->db->get_var("SELECT COUNT(*) FROM `$memberships`"));
        self::assertNotSame($world->a->id, $world->b->id);
        self::assertNotSame($world->a->publicId, $world->b->publicId);
        foreach ([$world->a, $world->b] as $account) {
            self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $account->publicId);
            $settings = FixtureSchema::table($this->db, 'settings');
            $row = $this->db->get_row($this->db->prepare(
                "SELECT timezone, currency, locale, receipt_prefix FROM `$settings` WHERE account_id = %d",
                $account->id
            ), ARRAY_A);
            self::assertSame([
                'timezone' => 'Africa/Cairo', 'currency' => 'EGP', 'locale' => 'ar', 'receipt_prefix' => 'RCPT-',
            ], $row);
            $renters = FixtureSchema::table($this->db, 'renters');
            self::assertSame((string) $account->user('renter'), $this->db->get_var($this->db->prepare(
                "SELECT portal_user_id FROM `$renters` WHERE account_id = %d AND id = %d",
                $account->id,
                $account->renterId
            )));
            foreach (['owner', 'manager', 'renter'] as $role) {
                $user = get_userdata($account->user($role));
                self::assertNotFalse($user);
                self::assertSame(['rm_client'], $user->roles);
                $table = FixtureSchema::table($this->db, 'memberships');
                $row = $this->db->get_row($this->db->prepare(
                    "SELECT * FROM `$table` WHERE account_id = %d AND user_id = %d",
                    $account->id,
                    $user->ID
                ), ARRAY_A);
                self::assertIsArray($row);
                self::assertSame($role, $row['role']);
                self::assertSame('active', $row['status']);
                self::assertSame(
                    $role === 'renter' ? $account->renterId : null,
                    $row['renter_id'] === null ? null : (int) $row['renter_id']
                );
            }
        }
        self::assertCount(6, array_unique(array_merge(array_values($world->a->users), array_values($world->b->users))));
    }

    public function testWordPressAndFixtureRowsActuallyRollBack(): void
    {
        $world = new World($this->db);
        $table = FixtureSchema::table($this->db, 'probe');
        self::assertSame(1, $this->db->insert($table, [
            'account_id' => $world->a->id, 'public_id' => str_repeat('0', 25) . 'Z', 'note' => 'rollback proof',
        ]));
        self::assertSame('1', $this->db->get_var("SELECT COUNT(*) FROM `$table`"));
        FixtureSchema::query($this->db, 'ROLLBACK');
        wp_cache_flush();
        self::assertSame('0', $this->db->get_var("SELECT COUNT(*) FROM `$table`"));
        self::assertFalse(get_userdata($world->a->user('owner')));
        $accounts = FixtureSchema::table($this->db, 'accounts');
        self::assertSame('0', $this->db->get_var("SELECT COUNT(*) FROM `$accounts`"));
        FixtureSchema::query($this->db, 'START TRANSACTION');
    }

    public function testCompositeForeignKeyRejectsAnotherAccountsRenter(): void
    {
        $world = new World($this->db);
        $table = FixtureSchema::table($this->db, 'memberships');
        $previous = $this->db->suppress_errors();
        try {
            $result = $this->db->update($table, ['renter_id' => $world->b->renterId], [
                'id' => $world->a->memberships['renter'], 'account_id' => $world->a->id,
            ]);
            self::assertFalse($result);
            self::assertStringContainsString('foreign key constraint fails', $this->db->last_error);
        } finally {
            $this->db->suppress_errors($previous);
        }
    }
}
