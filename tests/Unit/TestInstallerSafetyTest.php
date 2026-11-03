<?php

declare(strict_types=1);

namespace RentalManager\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentalManager\Tests\Support\WordPressTestInstaller;
use RuntimeException;

final class TestInstallerSafetyTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('RM_ALLOW_TEST_DB_RESET');
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'RM_ALLOW_TEST_DB_RESET' : 'RM_ALLOW_TEST_DB_RESET=' . $this->previous);
    }

    /** @return list<array{string}> */
    public static function unsafeNames(): array
    {
        return [['wordpress'], ['production'], ['rm_test_x; DROP DATABASE wordpress'], ['rm_test_'], ['../rm_test_x']];
    }

    #[DataProvider('unsafeNames')]
    public function testRefusesNonTestDatabaseBeforeAnyNetworkOrSql(string $name): void
    {
        putenv('RM_ALLOW_TEST_DB_RESET=1');
        $this->expectException(RuntimeException::class);
        WordPressTestInstaller::assertDisposable($name);
    }

    public function testRequiresExplicitResetOptIn(): void
    {
        putenv('RM_ALLOW_TEST_DB_RESET');
        $this->expectException(RuntimeException::class);
        WordPressTestInstaller::assertDisposable('rm_test_rental_manager');
    }

    public function testAcceptsExplicitlyOptedInTestDatabase(): void
    {
        putenv('RM_ALLOW_TEST_DB_RESET=1');
        WordPressTestInstaller::assertDisposable('rm_test_rental_manager');
        $this->addToAssertionCount(1);
    }
}
