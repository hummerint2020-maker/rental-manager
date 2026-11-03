<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support;

use RuntimeException;
use WP_UnitTest_Factory;
use wpdb;

final class World
{
    private static int $sequence = 0;
    public readonly TestAccount $a;
    public readonly TestAccount $b;

    public function __construct(private wpdb $db, ?WP_UnitTest_Factory $factory = null)
    {
        $factory ??= new WP_UnitTest_Factory();
        $this->a = $this->account('A', $factory);
        $this->b = $this->account('B', $factory);
    }

    private function account(string $name, WP_UnitTest_Factory $factory): TestAccount
    {
        $publicId = self::publicId();
        $account = $this->insert('accounts', ['public_id' => $publicId, 'name' => 'Account ' . $name]);
        $this->insert('settings', [
            'account_id' => $account, 'public_id' => self::publicId(),
            'timezone' => 'Africa/Cairo', 'currency' => 'EGP', 'locale' => 'ar', 'receipt_prefix' => 'RCPT-',
        ]);
        $renter = $this->insert('renters', [
            'account_id' => $account, 'public_id' => self::publicId(), 'full_name' => 'Renter ' . $name,
        ]);
        $users = [];
        $memberships = [];
        foreach (['owner', 'manager', 'renter'] as $role) {
            $key = strtolower(self::publicId());
            $user = $factory->user->create([
                'user_login' => $role . '_' . $key, 'user_email' => $role . '_' . $key . '@example.test',
                'role' => 'rm_client',
            ]);
            if (is_wp_error($user) || !is_int($user) || $user < 1) {
                throw new RuntimeException('Cannot create WordPress fixture user.');
            }
            $users[$role] = $user;
            $memberships[$role] = $this->insert('memberships', [
                'account_id' => $account, 'public_id' => self::publicId(), 'user_id' => $user,
                'role' => $role, 'status' => 'active', 'renter_id' => $role === 'renter' ? $renter : null,
            ]);
        }
        if (
            $this->db->update(FixtureSchema::table($this->db, 'renters'), [
            'portal_user_id' => $users['renter'],
            ], ['id' => $renter, 'account_id' => $account]) === false
        ) {
            throw new RuntimeException($this->db->last_error);
        }
        return new TestAccount($account, $publicId, $renter, $users, $memberships);
    }

    /** @param array<string, int|string|null> $values */
    private function insert(string $table, array $values): int
    {
        if ($this->db->insert(FixtureSchema::table($this->db, $table), $values) === false) {
            throw new RuntimeException($this->db->last_error);
        }
        return (int) $this->db->insert_id;
    }

    private static function publicId(): string
    {
        // Valid deterministic Crockford characters for fixtures, not the Phase 1 production ULID generator.
        return str_pad(strtoupper(dechex(++self::$sequence)), 26, '0', STR_PAD_LEFT);
    }
}
