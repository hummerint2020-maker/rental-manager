<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use RentalManager\Kernel\Health\AdminPage;
use RentalManager\Kernel\Migrations\AdminRecovery;
use RentalManager\Kernel\Migrations\Diagnostics;
use RentalManager\Kernel\Migrations\FailureState;
use RentalManager\Kernel\Migrations\Lifecycle;
use RentalManager\Kernel\Migrations\MigrationException;
use RentalManager\Kernel\Migrations\MigrationSession;
use RentalManager\Kernel\Migrations\SchemaGate;
use RentalManager\Tests\Support\CommittedDatabaseTestCase;
use RuntimeException;

final class MigrationAdminRecoveryTest extends CommittedDatabaseTestCase
{
    protected function prepareCommittedFixtures(): void
    {
        $this->trackOption('rm_schema_version');
        $this->trackOption(FailureState::OPTION);
        update_option('rm_schema_version', '0.0.0');
        FailureState::record('0.1.0', new MigrationException('Must never be displayed', 1234));
        $this->ownedTable('migrations');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        add_filter('wp_die_handler', static fn (): callable => static function ($message): void {
            throw new RuntimeException((string) $message);
        });
    }

    public function testRetryRejectsClientsGetAndInvalidOrUnrelatedNoncesWithoutClearingFailure(): void
    {
        $admin = $this->createOwnedUser(['role' => 'administrator']);
        $client = $this->createOwnedUser(['role' => 'subscriber']);
        $before = FailureState::read();
        $calls = 0;
        $file = dirname(__DIR__, 2) . '/rental-manager.php';
        $lifecycle = new Lifecycle($file, function () use (&$calls): MigrationSession {
            ++$calls;
            return new MigrationSession($this->db, $this->migrationPrefix());
        }, false);
        $recovery = new AdminRecovery($lifecycle);
        foreach (
            [[$client, 'POST', 'rm_retry_migrations'], [$admin, 'GET', 'rm_retry_migrations'],
                [$admin, 'POST', 'invalid'], [$admin, 'POST', 'rm_health_checks']] as [$user, $method, $action]
        ) {
            wp_set_current_user($user);
            $_SERVER['REQUEST_METHOD'] = $method;
            $_REQUEST['_wpnonce'] = $action === 'invalid' ? 'invalid' : wp_create_nonce($action);
            try {
                $recovery->retry();
                self::fail('Recovery requires its own POST nonce and administrator capability.');
            } catch (RuntimeException) {
                self::assertSame($before, FailureState::read());
                self::assertSame(0, $calls);
            }
        }
        unset($_REQUEST['_wpnonce']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testAuthorizedRetryClearsFailureAndRecoversOnceAfterCauseIsFixed(): void
    {
        wp_set_current_user($this->createOwnedUser(['role' => 'administrator']));
        $_REQUEST['_wpnonce'] = wp_create_nonce('rm_retry_migrations');
        $calls = 0;
        $file = dirname(__DIR__, 2) . '/rental-manager.php';
        $lifecycle = new Lifecycle($file, function () use (&$calls): MigrationSession {
            ++$calls;
            return new MigrationSession($this->db, $this->migrationPrefix());
        }, false);
        $recovery = new AdminRecovery($lifecycle);
        $redirect = '';
        add_filter('wp_redirect', static function (string $url) use (&$redirect): bool {
            $redirect = $url;
            return false; // Capture the redirect without sending headers in PHPUnit.
        });
        try {
            $recovery->retry();
            self::assertSame(1, $calls);
            self::assertNull(FailureState::read());
            self::assertTrue((new SchemaGate($file))->isReady());
            self::assertStringContainsString('page=rental-manager-health', $redirect);
        } finally {
            unset($_REQUEST['_wpnonce']);
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
    }

    public function testHealthRemainsReachableAndListsIncompleteJournalWithReconciliationInstructions(): void
    {
        wp_set_current_user($this->createOwnedUser(['role' => 'administrator']));
        $file = dirname(__DIR__, 2) . '/rental-manager.php';
        $session = new MigrationSession($this->db, $this->migrationPrefix());
        $session->withLock(static function () use ($session): void {
            $session->appliedMigrations();
            $session->markStarted('0001_needs_reconciliation');
        });
        FailureState::record('0.1.0', new MigrationException(
            'Private database error',
            1234,
            migrationId: '0001_needs_reconciliation'
        ));
        $diagnostics = new Diagnostics($file, $this->db, $this->migrationPrefix() . 'migrations');
        $recovery = new AdminRecovery(new Lifecycle($file, fn () => $session, false), $diagnostics);
        self::assertSame('fail', $recovery->result()->status);
        self::assertStringContainsString('0001_needs_reconciliation', $recovery->result()->detail);
        ob_start();
        $recovery->notice();
        $recovery->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Open System Health', $html);
        self::assertStringContainsString('0001_needs_reconciliation', $html);
        self::assertStringContainsString('reconciled with the actual schema', $html);
        self::assertStringContainsString(wp_create_nonce('rm_retry_migrations'), $html);
        self::assertStringNotContainsString('Private database error', $html);
        $_REQUEST['_wpnonce'] = wp_create_nonce('rm_retry_migrations');
        add_filter('wp_redirect', static fn (): bool => false);
        try {
            $recovery->retry();
            self::assertSame('0001_needs_reconciliation', FailureState::read()['migration_id'] ?? null);
            self::assertSame(['0001_needs_reconciliation'], $diagnostics->journal()['incomplete']);
        } finally {
            unset($_REQUEST['_wpnonce']);
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        (new AdminPage())->render();
        $page = (string) ob_get_clean();
        self::assertStringContainsString('Database schema', $page);
        self::assertStringContainsString('Retry migrations', $page);
        wp_set_current_user($this->createOwnedUser(['role' => 'subscriber']));
        ob_start();
        $recovery->notice();
        self::assertSame('', ob_get_clean());
    }

    public function testNewRecoveryStringsAreBundledInArabic(): void
    {
        $catalog = new \MO();
        self::assertTrue($catalog->import_from_file(dirname(__DIR__, 2) . '/languages/rental-manager-ar.mo'));
        foreach (['Database schema', 'Retry migrations', 'Open System Health', 'Incomplete migrations'] as $message) {
            self::assertNotSame($message, $catalog->translate($message));
        }
    }
}
