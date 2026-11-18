<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use RentalManager\Kernel\Migrations\FailureState;
use RentalManager\Kernel\Migrations\Lifecycle;
use RentalManager\Kernel\Migrations\MigrationException;
use RentalManager\Kernel\Migrations\MigrationSession;
use RentalManager\Kernel\Migrations\SchemaGate;
use RentalManager\Tests\Support\CommittedDatabaseTestCase;
use wpdb;

final class MigrationRecoveryTest extends CommittedDatabaseTestCase
{
    protected function prepareCommittedFixtures(): void
    {
        $this->trackOption('rm_schema_version');
        $this->trackOption(FailureState::OPTION);
        delete_option('rm_schema_version');
        FailureState::clear();
        $this->ownedTable('migrations');
        add_filter('wp_die_handler', static fn (): callable => static function (): void {
            self::fail('A migration problem must not terminate a normal WordPress request.');
        });
    }

    public function testNormalInitReturnsQuietlyWhenAnotherSessionHoldsTheLock(): void
    {
        $this->withPlugin(function (string $file): void {
            $lifecycle = new Lifecycle($file, $this->migrationSession(...), false);
            $gate = new SchemaGate($file);
            $holder = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            try {
                (new MigrationSession($holder, $this->migrationPrefix()))->withLock(
                    static function () use ($lifecycle, $gate): void {
                        $lifecycle->onInit();
                        self::assertFalse($gate->isReady());
                        self::assertFalse(get_option(FailureState::OPTION));
                        self::assertFalse(get_option('rm_schema_version'));
                    }
                );
            } finally {
                $holder->close();
            }
            $lifecycle->onInit();
            self::assertTrue($gate->isReady());
        });
    }

    public function testFailedMigrationLeavesWordPressHooksRunningAndBlocksAutomaticReplay(): void
    {
        $this->withPlugin(function (string $file): void {
            file_put_contents(dirname($file) . '/migrations/0001_failure.php', <<<'PHP'
<?php
return new class implements \RentalManager\Kernel\Migrations\Migration {
    public function up(\RentalManager\Kernel\Migrations\Schema $schema): void {
        throw new \RuntimeException('private SQL / personal data', 1234);
    }
};
PHP);
            $sessionRequests = 0;
            $factory = function () use (&$sessionRequests): MigrationSession {
                ++$sessionRequests;
                return $this->migrationSession();
            };
            $lifecycle = new Lifecycle($file, $factory, false);
            $gate = new SchemaGate($file);
            remove_all_actions('init');
            remove_all_actions('wp_loaded');
            remove_all_actions('admin_init');
            $lifecycle->register();
            $hookCalls = [];
            add_action('wp_loaded', static function () use (&$hookCalls): void {
                $hookCalls[] = 'wp_loaded';
            });
            add_action('admin_init', static function () use (&$hookCalls): void {
                $hookCalls[] = 'admin_init';
            });

            do_action('init');
            do_action('wp_loaded');
            do_action('admin_init');
            self::assertSame(['wp_loaded', 'admin_init'], $hookCalls);
            self::assertSame(1, $sessionRequests);
            self::assertFalse(get_option('rm_schema_version'));
            self::assertFalse($gate->isReady());
            $failure = FailureState::read();
            self::assertNotNull($failure);
            self::assertSame('0.1.0', $failure['version']);
            self::assertSame('0001_failure', $failure['migration_id']);
            self::assertSame(1234, $failure['error_code']);
            self::assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',
                $failure['failed_at']
            );
            self::assertStringNotContainsString('private SQL', serialize(get_option(FailureState::OPTION)));
            $autoload = $this->db->get_var($this->db->prepare(
                'SELECT autoload FROM %i WHERE option_name = %s',
                $this->db->options,
                FailureState::OPTION
            ));
            self::assertIsString($autoload);
            self::assertNotContains($autoload, wp_autoload_values_to_autoload());

            do_action('init');
            self::assertSame(1, $sessionRequests);
            self::assertSame($failure, FailureState::read());
            update_option('rm_schema_version', '0.1.0');
            self::assertFalse($gate->isReady());

            $lifecycle->retry();
            self::assertSame(2, $sessionRequests);
            self::assertFalse($gate->isReady());
            $retryFailure = FailureState::read();
            self::assertNotNull($retryFailure);
            self::assertSame('0001_failure', $retryFailure['migration_id']);
            $table = $this->migrationPrefix() . 'migrations';
            self::assertSame('1', $this->db->get_var($this->db->prepare(
                'SELECT COUNT(*) FROM %i WHERE applied_at IS NULL',
                $table
            )));
        });
    }

    #[DataProvider('recoveryActions')]
    public function testRecoversAfterFixingCatalogBeforeAnyMigrationStarted(bool $newVersion): void
    {
        $this->withPlugin(function (string $file) use ($newVersion): void {
            $badFile = dirname($file) . '/migrations/invalid.php';
            file_put_contents($badFile, '<?php return null;');
            $lifecycle = new Lifecycle($file, $this->migrationSession(...), false);
            $gate = new SchemaGate($file);
            $lifecycle->onInit();
            self::assertNotNull(FailureState::read());
            self::assertFalse($gate->isReady());
            unlink($badFile);

            if ($newVersion) {
                $this->writePluginVersion($file, '0.1.1');
                $lifecycle->onInit();
            } else {
                $lifecycle->retry();
            }
            self::assertNull(FailureState::read());
            self::assertSame($newVersion ? '0.1.1' : '0.1.0', get_option('rm_schema_version'));
            self::assertTrue($gate->isReady());
        });
    }

    /** @return iterable<string, array{bool}> */
    public static function recoveryActions(): iterable
    {
        yield 'manual retry' => [false];
        yield 'new-version upgrade' => [true];
    }

    public function testCliInitThrowsMigrationFailureWithoutCallingWpDie(): void
    {
        $this->withPlugin(function (string $file): void {
            file_put_contents(dirname($file) . '/migrations/invalid.php', '<?php return null;');
            $lifecycle = new Lifecycle($file, $this->migrationSession(...), true);
            $this->expectException(MigrationException::class);
            $lifecycle->onInit();
        });
    }

    private function migrationSession(): MigrationSession
    {
        return new MigrationSession($this->db, $this->migrationPrefix());
    }

    /** @param callable(string): void $test */
    private function withPlugin(callable $test): void
    {
        $directory = sys_get_temp_dir() . '/rm-recovery-' . bin2hex(random_bytes(8));
        mkdir($directory . '/migrations', 0700, true);
        $file = $directory . '/rental-manager.php';
        $this->writePluginVersion($file, '0.1.0');
        try {
            $test($file);
        } finally {
            foreach (glob($directory . '/migrations/*') ?: [] as $migration) {
                unlink($migration);
            }
            rmdir($directory . '/migrations');
            unlink($file);
            rmdir($directory);
        }
    }

    private function writePluginVersion(string $file, string $version): void
    {
        file_put_contents($file, "<?php\n/*\nPlugin Name: Migration fixture\nVersion: $version\n*/\n");
    }
}
