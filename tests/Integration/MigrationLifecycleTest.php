<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use RentalManager\Kernel\Migrations\FailureState;
use RentalManager\Kernel\Migrations\Lifecycle;
use RentalManager\Kernel\Migrations\MigrationException;
use RentalManager\Kernel\Migrations\MigrationSession;
use RentalManager\Tests\Support\CommittedDatabaseTestCase;
use RuntimeException;

final class MigrationLifecycleTest extends CommittedDatabaseTestCase
{
    protected function prepareCommittedFixtures(): void
    {
        $this->trackOption('rm_schema_version');
        $this->trackOption(FailureState::OPTION);
        delete_option('rm_schema_version');
        FailureState::clear();
        $this->ownedTable('migrations');
    }

    public function testVersionChangeAndActivationInstallJournalAndRecordVersion(): void
    {
        $this->withPlugin(function (Lifecycle $lifecycle): void {
            $lifecycle->migrate();
            self::assertSame('0.1.0', get_option('rm_schema_version'));
            $table = $this->migrationPrefix() . 'migrations';
            self::assertSame($table, $this->db->get_var($this->db->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )));
            $lifecycle->activate();
            self::assertSame('0.1.0', get_option('rm_schema_version'));
            self::assertSame('0', $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM %i', $table)));
        });
    }

    public function testMatchingVersionDoesNotConstructSession(): void
    {
        update_option('rm_schema_version', '0.1.0');
        $this->withPlugin(static function (Lifecycle $unused, string $file): void {
            (new Lifecycle($file, static function (): MigrationSession {
                throw new RuntimeException('The migration session must not open on the fast path.');
            }))->migrate();
            self::assertSame('0.1.0', get_option('rm_schema_version'));
        });
    }

    public function testUnderLockRecheckRejectsDowngradeEvenWithStaleOptionCache(): void
    {
        update_option('rm_schema_version', '0.0.0');
        self::assertSame('0.0.0', get_option('rm_schema_version'));
        $this->db->query($this->db->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s',
            $this->db->options,
            '0.2.0',
            'rm_schema_version'
        ));
        $this->withPlugin(function (Lifecycle $lifecycle): void {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('newer plugin version');
            $lifecycle->migrate();
        });
    }

    public function testFailedMigrationDoesNotAdvanceVersionOrExposeDatabaseDetails(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $this->withPlugin(function (Lifecycle $lifecycle, string $file): void {
            file_put_contents(dirname($file) . '/migrations/0001_failure.php', <<<'PHP'
<?php
return new class implements \RentalManager\Kernel\Migrations\Migration {
    public function up(\RentalManager\Kernel\Migrations\Schema $schema): void {
        throw new \RuntimeException('private SQL / personal data');
    }
};
PHP);
            add_filter('wp_die_handler', static fn () => static function (string $message): void {
                throw new RuntimeException($message);
            });
            try {
                $lifecycle->activate();
                self::fail('Failed activation must report a safe localized error.');
            } catch (RuntimeException $error) {
                self::assertInstanceOf(MigrationException::class, $error);
                self::assertNotNull($error->getPrevious());
                self::assertStringContainsString('could not update its database', $error->getMessage());
                self::assertStringNotContainsString('private SQL', $error->getMessage());
            }
            self::assertFalse(get_option('rm_schema_version'));
        });
    }

    public function testUnderLockMatchingVersionClearsStaleWordPressCache(): void
    {
        update_option('rm_schema_version', '0.0.0');
        self::assertSame('0.0.0', get_option('rm_schema_version'));
        $this->db->query($this->db->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s',
            $this->db->options,
            '0.1.0',
            'rm_schema_version'
        ));
        $this->withPlugin(function (Lifecycle $lifecycle): void {
            $lifecycle->migrate();
            self::assertSame('0.1.0', get_option('rm_schema_version'));
        });
    }

    /** @param callable(Lifecycle, string): void $test */
    private function withPlugin(callable $test): void
    {
        $directory = sys_get_temp_dir() . '/rm-lifecycle-' . bin2hex(random_bytes(8));
        mkdir($directory . '/migrations', 0700, true);
        $file = $directory . '/rental-manager.php';
        file_put_contents($file, "<?php\n/*\nPlugin Name: Migration fixture\nVersion: 0.1.0\n*/\n");
        try {
            $test(new Lifecycle($file, fn (): MigrationSession => new MigrationSession(
                $this->db,
                $this->migrationPrefix()
            )), $file);
        } finally {
            foreach (glob($directory . '/migrations/*') ?: [] as $migration) {
                unlink($migration);
            }
            rmdir($directory . '/migrations');
            unlink($file);
            rmdir($directory);
        }
    }
}
