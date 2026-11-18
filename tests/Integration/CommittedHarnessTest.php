<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use RentalManager\Kernel\Db\DbException;
use RentalManager\Tests\Support\CommittedDatabaseTestCase;
use RentalManager\Tests\Support\FixtureSchema;
use RuntimeException;

final class CommittedHarnessTest extends CommittedDatabaseTestCase
{
    #[DataProvider('setupOutcomes')]
    public function testCleansOnlyOwnedResourcesAfterNormalOrFailedSetup(bool $failSetup): void
    {
        $preservedOption = 'rm_test_option_' . bin2hex(random_bytes(6));
        $this->trackOption($preservedOption);
        self::assertTrue(add_option($preservedOption, 'original'));
        $parentTable = $this->ownedTable('outer');
        FixtureSchema::query($this->db, 'CREATE TABLE `' . $parentTable . '` (id INT PRIMARY KEY)'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
        $savedUser = $this->createOwnedUser();
        wp_set_current_user($savedUser);

        $probe = new class ('probe') extends CommittedDatabaseTestCase {
            public string $preservedOption;
            public bool $failSetup;
            public string $parent;
            public string $child;
            public int $row;
            public int $user;
            public string $option;

            public function open(): void
            {
                $this->setUp();
            }

            public function close(): void
            {
                $this->tearDown();
            }

            protected function prepareCommittedFixtures(): void
            {
                $this->parent = $this->ownedTable('parent');
                $this->child = $this->ownedTable('child');
                FixtureSchema::query($this->db, 'CREATE TABLE `' . $this->parent
                    . '` (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
                FixtureSchema::query($this->db, 'CREATE TABLE `' . $this->child
                    . '` (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES `'
                    . $this->parent . '` (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
                self::assertSame(1, $this->db->insert($this->parent, ['id' => 1]));
                self::assertSame(1, $this->db->insert($this->child, ['id' => 1, 'parent_id' => 1]));

                $accounts = FixtureSchema::table($this->db, 'accounts');
                self::assertSame(1, $this->db->insert($accounts, [
                    'public_id' => strtoupper(bin2hex(random_bytes(13))), 'name' => 'Committed fixture',
                ]));
                $this->row = (int) $this->db->insert_id;
                $this->trackFixtureRow('accounts', $this->row);
                $this->user = $this->createOwnedUser();
                $this->option = 'rm_test_option_' . bin2hex(random_bytes(6));
                $this->trackOption($this->option);
                self::assertTrue(add_option($this->option, 'temporary'));
                $this->trackOption($this->preservedOption);
                self::assertTrue(update_option($this->preservedOption, 'changed'));
                $this->trackOption($this->preservedOption);
                add_action('rm_committed_test_action', static function (): void {
                });
                do_action('rm_committed_test_action');
                wp_set_current_user($this->user);

                if ($this->failSetup) {
                    throw new RuntimeException('Simulated setup failure.');
                }
            }
        };
        $probe->preservedOption = $preservedOption;
        $probe->failSetup = $failSetup;
        try {
            $probe->open();
            self::assertFalse($failSetup, 'Setup should have failed.');
            $this->assertCommittedResourcesVisible($probe->parent, $probe->row);
        } catch (RuntimeException $failure) {
            self::assertTrue($failSetup);
            self::assertSame('Simulated setup failure.', $failure->getMessage());
            $this->assertResourcesCleaned($probe->parent, $probe->child, $probe->row, $probe->user, $probe->option);
        } finally {
            $probe->close();
        }

        $this->assertResourcesCleaned($probe->parent, $probe->child, $probe->row, $probe->user, $probe->option);
        self::assertTrue($this->tableExists($parentTable));
        self::assertSame('original', get_option($preservedOption));
        self::assertSame($savedUser, get_current_user_id());
        self::assertFalse(has_action('rm_committed_test_action'));
        self::assertSame(0, did_action('rm_committed_test_action'));
    }

    /** @return iterable<string, array{bool}> */
    public static function setupOutcomes(): iterable
    {
        yield 'normal teardown' => [false];
        yield 'partially failed setup' => [true];
    }

    #[DataProvider('nonAutocommitSessions')]
    public function testGuardPreservesTheCallerTransactionAndCurrentUser(bool $implicit): void
    {
        $table = $this->ownedTable('sentinel');
        FixtureSchema::query($this->db, 'CREATE TABLE `' . $table . '` (id INT PRIMARY KEY)'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
        $user = $this->createOwnedUser();
        wp_set_current_user($user);
        $probe = new class ('probe') extends CommittedDatabaseTestCase {
            public function open(): void
            {
                $this->setUp();
            }

            public function close(): void
            {
                $this->tearDown();
            }

            protected function prepareCommittedFixtures(): void
            {
                self::fail('The guard must run before fixture setup.');
            }
        };

        FixtureSchema::query($this->db, $implicit ? 'SET autocommit = 0' : 'START TRANSACTION');
        try {
            self::assertSame(1, $this->db->insert($table, ['id' => 1]));
            try {
                $probe->open();
                self::fail('Committed fixtures require an idle connection in autocommit mode.');
            } catch (RuntimeException $error) {
                if ($implicit) {
                    self::assertSame('Committed fixtures require autocommit.', $error->getMessage());
                } else {
                    self::assertInstanceOf(DbException::class, $error);
                    self::assertStringContainsString('Nested database transactions', $error->getMessage());
                }
            } finally {
                $probe->close();
            }
            self::assertSame($user, get_current_user_id());
            self::assertSame('1', (string) $this->db->get_var($this->db->prepare('SELECT id FROM %i', $table)));
        } finally {
            FixtureSchema::query($this->db, 'ROLLBACK');
            if ($implicit) {
                FixtureSchema::query($this->db, 'SET autocommit = 1');
            }
        }
        self::assertNull($this->db->get_var($this->db->prepare('SELECT id FROM %i', $table)));
    }

    /** @return iterable<string, array{bool}> */
    public static function nonAutocommitSessions(): iterable
    {
        yield 'explicit transaction' => [false];
        yield 'autocommit disabled' => [true];
    }

    private function assertCommittedResourcesVisible(string $table, int $account): void
    {
        $reader = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        try {
            self::assertSame('1', (string) $reader->get_var($reader->prepare('SELECT COUNT(*) FROM %i', $table)));
            self::assertSame('Committed fixture', $reader->get_var($reader->prepare(
                'SELECT name FROM %i WHERE id = %d',
                FixtureSchema::table($this->db, 'accounts'),
                $account
            )));
        } finally {
            $reader->close();
        }
    }

    private function assertResourcesCleaned(string $parent, string $child, int $row, int $user, string $option): void
    {
        self::assertFalse($this->tableExists($parent));
        self::assertFalse($this->tableExists($child));
        self::assertNull($this->db->get_var($this->db->prepare(
            'SELECT id FROM %i WHERE id = %d',
            FixtureSchema::table($this->db, 'accounts'),
            $row
        )));
        self::assertFalse(get_userdata($user));
        self::assertFalse(get_option($option));
    }

    private function tableExists(string $table): bool
    {
        return $this->db->get_var($this->db->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        )) === $table;
    }
}
