<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RentalManager\Kernel\Migrations\Migration;
use RentalManager\Kernel\Migrations\MigrationCatalog;
use RentalManager\Kernel\Migrations\MigrationException;
use RentalManager\Tests\Support\ArchitectureSandbox;

final class MigrationCatalogTest extends TestCase
{
    public function testOrdersCanonicalFilesWithoutRunningUp(): void
    {
        $sandbox = new ArchitectureSandbox();
        try {
            $directory = $sandbox->directory . '/src';
            file_put_contents($directory . '/.gitkeep', '');
            self::assertSame([], MigrationCatalog::load($directory));
            $source = <<<'PHP'
<?php
use RentalManager\Kernel\Migrations\Migration;
use RentalManager\Kernel\Migrations\Schema;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        throw new LogicException('Catalog loading must not run a migration.');
    }
};
PHP;
            file_put_contents($directory . '/0100_create_last.php', $source);
            file_put_contents($directory . '/0002_create_first.php', $source);

            $catalog = MigrationCatalog::load($directory);
            self::assertSame(['0002_create_first', '0100_create_last'], array_keys($catalog));
            self::assertContainsOnlyInstancesOf(Migration::class, $catalog);
        } finally {
            $sandbox->remove();
        }
    }

    public function testRejectsDuplicateNumbersBeforeRequiringAnyMigration(): void
    {
        $sandbox = new ArchitectureSandbox();
        try {
            $directory = $sandbox->directory . '/src';
            file_put_contents($directory . '/0001_create_first.php', '<?php throw new LogicException();');
            file_put_contents($directory . '/0001_create_second.php', '<?php throw new LogicException();');
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('Duplicate migration number 0001.');
            MigrationCatalog::load($directory);
        } finally {
            $sandbox->remove();
        }
    }

    public function testRejectsMalformedFilenameAndWrongReturnType(): void
    {
        $sandbox = new ArchitectureSandbox();
        try {
            $directory = $sandbox->directory . '/src';
            $path = $directory . '/1_create_first.php';
            file_put_contents($path, '<?php return null;');
            try {
                MigrationCatalog::load($directory);
                self::fail('Expected a malformed filename error.');
            } catch (MigrationException $error) {
                self::assertStringContainsString('numbered migration file', $error->getMessage());
            }
            rename($path, $directory . '/0001_create_first.php');
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('0001_create_first must return a Migration');
            MigrationCatalog::load($directory);
        } finally {
            $sandbox->remove();
        }
    }
}
