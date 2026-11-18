<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support;

use Closure;
use PHPUnit\Framework\TestCase;
use RentalManager\Kernel\Db\Connection;
use RuntimeException;
use stdClass;
use Throwable;
use WP_Hook;
use WP_UnitTest_Factory;
use wpdb;

abstract class CommittedDatabaseTestCase extends TestCase
{
    protected wpdb $db;

    private string $prefix;
    private ?int $previousUser = null;

    /** @var array<string, WP_Hook> */
    private array $hooks = [];
    /** @var array<string, int> */
    private array $actions = [];
    /** @var list<string> */
    private array $filters = [];

    /** @var list<Closure(): void> */
    private array $cleanup = [];
    /** @var array<string, true> */
    private array $tables = [];
    /** @var array<string, true> */
    private array $options = [];

    final protected function setUp(): void
    {
        parent::setUp();
        WordPressTestInstaller::assertDisposable(DB_NAME);
        $this->db = $GLOBALS['wpdb'];
        $connection = new Connection($this->db);
        if ($connection->fetchValue('SELECT @@session.autocommit FROM DUAL WHERE %d = 1', 1) !== '1') {
            throw new RuntimeException('Committed fixtures require autocommit.');
        }
        // The real transaction strategy refuses existing raw transactions without committing them.
        $connection->transaction(static function (): void {
        });
        $this->prefix = $this->db->prefix . 'rm_test_migrations_' . bin2hex(random_bytes(6)) . '_';
        foreach ($GLOBALS['wp_filter'] as $name => $hook) {
            $this->hooks[$name] = clone $hook;
        }
        $this->actions = $GLOBALS['wp_actions'];
        $this->filters = $GLOBALS['wp_current_filter'];
        $this->previousUser = get_current_user_id();

        try {
            wp_set_current_user(0);
            $this->prepareCommittedFixtures();
        } catch (Throwable $setupFailure) {
            try {
                $this->cleanFixtures();
            } catch (Throwable $cleanupFailure) {
                throw new RuntimeException(
                    'Committed fixture cleanup failed after setup failed: ' . $cleanupFailure->getMessage(),
                    0,
                    $setupFailure
                );
            }
            throw $setupFailure;
        }
    }

    protected function prepareCommittedFixtures(): void
    {
    }

    final protected function tearDown(): void
    {
        try {
            $this->cleanFixtures();
        } finally {
            parent::tearDown();
        }
    }

    protected function migrationPrefix(): string
    {
        return $this->prefix;
    }

    /** Reserve each table before DDL, in referenced-table-first order. */
    protected function ownedTable(string $suffix): string
    {
        $table = $this->migrationPrefix() . $suffix;
        if (
            $suffix === ''
            || strlen($table) > 64
            || preg_match('/\A[a-zA-Z0-9_]+\z/', $table) !== 1
        ) {
            throw new RuntimeException('Invalid committed fixture table name.');
        }
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = true;
            $this->cleanup[] = function () use ($table): void {
                FixtureSchema::query($this->db, 'DROP TABLE IF EXISTS `' . $table . '`');
            };
        }
        return $table;
    }

    /** Register only a row this test inserted into a shared FixtureSchema table. */
    protected function trackFixtureRow(string $fixture, int $id): void
    {
        if ($id < 1) {
            throw new RuntimeException('Invalid committed fixture row ID.');
        }
        $table = FixtureSchema::table($this->db, $fixture);
        $this->cleanup[] = function () use ($table, $id): void {
            if ($this->db->delete($table, ['id' => $id], ['%d']) === false) {
                throw new RuntimeException('Cannot clean up committed fixture row: ' . $table . ' #' . $id);
            }
        };
    }

    /** @param array<string, mixed> $args */
    protected function createOwnedUser(array $args = []): int
    {
        $factory = new WP_UnitTest_Factory();
        $user = $factory->user->create($args);
        if (!is_int($user) || $user < 1) {
            throw new RuntimeException('Cannot create committed fixture user.');
        }
        $this->cleanup[] = static function () use ($user): void {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            if (get_userdata($user) !== false && !wp_delete_user($user)) {
                throw new RuntimeException('Cannot clean up committed fixture user #' . $user);
            }
        };
        return $user;
    }

    /** Snapshot a plugin option before changing it. */
    protected function trackOption(string $name): void
    {
        if (preg_match('/\Arm_[a-z0-9_]+\z/', $name) !== 1) {
            throw new RuntimeException('Only Rental Manager plugin options can be tracked.');
        }
        if (isset($this->options[$name])) {
            return;
        }
        $missing = new stdClass();
        $original = get_option($name, $missing);
        $this->options[$name] = true;
        $this->cleanup[] = static function () use ($name, $original, $missing): void {
            if ($original === $missing) {
                delete_option($name);
            } else {
                update_option($name, $original);
            }
        };
    }

    private function cleanFixtures(): void
    {
        if ($this->previousUser === null) {
            return;
        }
        $failure = null;
        $GLOBALS['wp_filter'] = $this->hooks;
        try {
            while (($cleanup = array_pop($this->cleanup)) !== null) {
                try {
                    $cleanup();
                } catch (Throwable $error) {
                    $failure ??= $error;
                }
            }
        } finally {
            try {
                wp_cache_flush();
                wp_set_current_user($this->previousUser);
            } finally {
                $GLOBALS['wp_filter'] = $this->hooks;
                $GLOBALS['wp_actions'] = $this->actions;
                $GLOBALS['wp_current_filter'] = $this->filters;
                $this->previousUser = null;
                $this->tables = [];
                $this->options = [];
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
