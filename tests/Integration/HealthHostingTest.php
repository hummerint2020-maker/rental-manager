<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use RentalManager\Kernel\Health\AdminPage;
use RentalManager\Kernel\Health\Checks;
use RentalManager\Kernel\Health\DatabaseProbe;
use RentalManager\Kernel\Health\SchedulerProbe;
use RentalManager\Tests\Support\DatabaseTestCase;
use RentalManager\Tests\Support\FixtureSchema;
use RentalManager\Tests\Support\World;
use RuntimeException;
use wpdb;

final class HealthHostingTest extends DatabaseTestCase
{
    public function testArabicCatalogLoadsWithTheRealWordPressMoReader(): void
    {
        $catalog = new \MO();
        self::assertTrue($catalog->import_from_file(dirname(__DIR__, 2) . '/languages/rental-manager-ar.mo'));
        self::assertSame('سلامة النظام', $catalog->translate('System Health'));
        self::assertSame('مدير الإيجارات', $catalog->translate('Rental Manager'));
        self::assertSame('ناجح', $catalog->translate('Pass'));
        self::assertSame('تحذير', $catalog->translate('Warning'));
        self::assertSame(
            'MySQL 5.7.44 أو MariaDB 10.6 أو أحدث',
            $catalog->translate('MySQL 5.7.44 or MariaDB 10.6 or later')
        );
        self::assertSame(
            'انتهى الدعم الرسمي لـ MySQL 5.7',
            $catalog->translate('MySQL 5.7 is end-of-life upstream')
        );
        $guard = 'Rental Manager requires PHP 8.1 or later and WordPress 7.0 or later.';
        self::assertNotSame($guard, $catalog->translate($guard));
    }

    public function testDatabaseProbesUseIndependentConnectionAndCleanTables(): void
    {
        $world = new World($this->db);
        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->set_prefix($this->db->prefix);
        $pattern = $other->esc_like(substr($other->prefix, 0, 20) . 'rm_health_') . '%';
        $sql = $other->prepare('SHOW TABLES LIKE %s', $pattern);
        $before = $other->get_col($sql);
        try {
            $version = (string) $other->get_var('SELECT VERSION()');
            $results = (new DatabaseProbe($other))->run();
            $versionResults = Checks::databaseInformation($version);
            self::assertEquals($versionResults, array_slice($results, 0, count($versionResults)));
            foreach (array_slice($results, count($versionResults)) as $result) {
                self::assertSame('pass', $result->status, $result->id);
            }
            self::assertSame('pass', $results[0]->status, 'The CI database must meet the minimum.');
            self::assertSame($before, $other->get_col($sql));
            // The harness's outer transaction must survive the probe's DDL/rollback.
            $accounts = FixtureSchema::table($this->db, 'accounts');
            $visible = $other->get_var($other->prepare(
                "SELECT COUNT(*) FROM `$accounts` WHERE id = %d",
                $world->a->id
            ));
            self::assertSame('0', (string) $visible, 'The outer fixture transaction must remain uncommitted.');
        } finally {
            $other->close();
        }
    }

    public function testFailedDatabaseProbeStillRemovesItsOwnTables(): void
    {
        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->set_prefix($this->db->prefix);
        $pattern = $other->esc_like(substr($other->prefix, 0, 20) . 'rm_health_') . '%';
        $sql = $other->prepare('SHOW TABLES LIKE %s', $pattern);
        $before = $other->get_col($sql);
        add_filter('query', static function (string $query): string {
            return str_starts_with($query, 'CREATE TABLE') && str_contains($query, 'rm_health_')
                && str_contains($query, 'FOREIGN KEY') ? 'INVALID HEALTH PROBE SQL' : $query;
        });
        try {
            $results = array_column((new DatabaseProbe($other))->run(), 'status', 'id');
            self::assertSame('fail', $results['foreign_keys']);
            self::assertSame('fail', $results['rollback']);
            self::assertSame($before, $other->get_col($sql));
        } finally {
            $other->close();
        }
    }

    public function testClientWordPressRoleIsDeniedBeforeAnyDiagnostic(): void
    {
        $user = self::factoryUser('rm_client');
        wp_set_current_user($user);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->installDieHandler();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You are not allowed');
        (new AdminPage())->render();
    }

    public function testAdministratorGetIsPassiveAndInvalidNonceIsRejected(): void
    {
        wp_set_current_user(self::factoryUser('administrator'));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        (new AdminPage())->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Run checks', $html);
        self::assertFalse(get_transient('rm_health_runner_' . get_current_user_id()));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['_wpnonce'] = 'invalid';
        $this->installDieHandler();
        try {
            (new AdminPage())->render();
            self::fail('An invalid nonce must be rejected before probes run.');
        } catch (RuntimeException $error) {
            self::assertFalse(get_transient('rm_health_runner_' . get_current_user_id()));
        } finally {
            unset($_REQUEST['_wpnonce']);
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
    }

    public function testMySql57EndOfLifeRendersAsWarningOnTheAdminPage(): void
    {
        wp_set_current_user(self::factoryUser('administrator'));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['_wpnonce'] = wp_create_nonce('rm_health_checks');
        add_filter('query', static fn (string $sql): string => $sql === 'SELECT VERSION()'
            ? "SELECT '5.7.44-48-log'" : $sql);
        add_filter('pre_http_request', static fn () => new \WP_Error('no_loopback', 'Test transport disabled.'));
        ob_start();
        try {
            (new AdminPage())->render();
            $html = (string) ob_get_contents();
            self::assertStringContainsString(
                '<td>MySQL 5.7 is end-of-life upstream</td><td>Warning</td>',
                $html
            );
            self::assertStringContainsString(
                '<td>MySQL 5.7.44 or MariaDB 10.6 or later</td><td>Pass</td>',
                $html
            );
        } finally {
            ob_end_clean();
            unset($_REQUEST['_wpnonce']);
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
    }

    public function testSchedulerCanaryStartsPendingAndPassesOnlyAfterExecution(): void
    {
        wp_set_current_user(self::factoryUser('administrator'));
        $probe = new SchedulerProbe();
        self::assertSame('pending', $probe->run()->status);
        do_action('action_scheduler_run_queue', 'Rental Manager health test');
        self::assertSame('pass', $probe->run()->status);
    }

    public function testSchedulerTimeoutFailsAndCancelsOnlyItsOwnCanary(): void
    {
        wp_set_current_user(self::factoryUser('administrator'));
        $token = bin2hex(random_bytes(16));
        as_enqueue_async_action('rm_health_canary', [$token], 'rm-health');
        set_transient('rm_health_runner_' . get_current_user_id(), ['token' => $token, 'started' => time() - 121], 300);
        self::assertSame('fail', (new SchedulerProbe())->run()->status);
        self::assertFalse(as_has_scheduled_action('rm_health_canary', [$token], 'rm-health'));
    }

    private static function factoryUser(string $role): int
    {
        $factory = new \WP_UnitTest_Factory();
        $user = $factory->user->create(['role' => $role]);
        self::assertIsInt($user);
        return $user;
    }

    private function installDieHandler(): void
    {
        add_filter('wp_die_handler', static fn (): callable => static function ($message): void {
            throw new RuntimeException(is_scalar($message) ? (string) $message : 'WordPress rejected request.');
        });
    }
}
