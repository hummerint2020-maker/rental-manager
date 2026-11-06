<?php

declare(strict_types=1);

namespace RentalManager\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentalManager\Architecture\BoundaryInspector;

final class BoundaryInspectorTest extends TestCase
{
    private static BoundaryInspector $inspector;

    public static function setUpBeforeClass(): void
    {
        self::$inspector = new BoundaryInspector(
            dirname(__DIR__, 2) . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php'
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function violations(): iterable
    {
        yield 'bare wpdb' => ['Units/Domain', 'global $wpdb; $wpdb->get_results("SELECT 1");', '$wpdb'];
        yield 'domain container' => [
            'Units/Domain', 'new \\RentalManager\\Kernel\\Container\\Container();', 'Service locator',
        ];
        yield 'application container type' => [
            'Units/Application', '$type = \\RentalManager\\Kernel\\Container\\Container::class;', 'Service locator',
        ];
        yield 'string container lookup' => [
            'Units/Application', '$type = "RentalManager\\\\Kernel\\\\Container\\\\Container";', 'Service locator',
        ];
        yield 'GLOBALS wpdb' => ['Units/Application', '$GLOBALS["wpdb"]->query("SELECT 1");', '$wpdb'];
        yield 'property wpdb' => ['Units/Presentation', '$this->wpdb->query("SELECT 1");', '$wpdb'];
        yield 'WordPress function' => ['Units/Domain', 'get_option("rm_test");', 'WordPress'];
        yield 'WordPress application' => ['Units/Application', '\\wp_mail("a", "b", "c");', 'WordPress'];
        yield 'WordPress presentation' => ['Units/Presentation', 'get_current_user_id();', 'WordPress'];
        yield 'WordPress type' => ['Units/Domain', 'new \\WP_User();', 'WordPress'];
        yield 'WordPress callback' => ['Units/Domain', '$callback = "get_option";', 'WordPress'];
        yield 'filesystem' => ['Units/Domain', 'file_get_contents("private.txt");', 'Native I/O'];
        yield 'filesystem class' => ['Units/Domain', 'new \\SplFileObject("private.txt");', 'Native I/O'];
        yield 'email' => ['Units/Domain', 'mail("a", "b", "c");', 'Native I/O'];
        yield 'direct SQL' => ['Units/Domain', '$sql = "SELECT * FROM rm_units";', 'SQL belongs'];
        yield 'request data' => ['Units/Domain', '$input = $_POST["account_id"];', 'Request and global'];
        yield 'foreign SQL table' => ['Billing/Infrastructure', '$table = "rm_units";', 'belongs to Units'];
        yield 'reporting write SQL' => ['Reporting/Infrastructure', '$sql = "DELETE FROM rm_units";', 'read-only'];
        yield 'reporting write adapter' => [
            'Reporting/Infrastructure', '$db->insert("rm_units", []);', 'write methods',
        ];
        yield 'include' => ['Units/Domain', 'require "adapter.php";', 'Native I/O'];
        yield 'shell' => ['Units/Domain', '`whoami`;', 'Native I/O'];
        yield 'pure Kernel' => ['Kernel/Money', 'file_get_contents("rate.txt");', 'Native I/O'];
        yield 'pure tenancy I/O' => ['Kernel/Tenancy', 'file_get_contents("account.txt");', 'Native I/O'];
        yield 'pure errors I/O' => ['Kernel/Errors', 'mail("a", "b", "c");', 'Native I/O'];
        yield 'pure tenancy WordPress' => ['Kernel/Tenancy', 'get_current_user_id();', 'WordPress'];
        yield 'pure errors WordPress' => ['Kernel/Errors', 'get_option("error_message");', 'WordPress'];
        yield 'uppercase SQL' => ['Units/Domain', '$sql = "SELECT id FROM rm_units";', 'SQL belongs'];
        yield 'translate function is still forbidden' => ['Units/Domain', 'translate("message");', 'WordPress'];
    }

    #[DataProvider('violations')]
    public function testRejectsInfrastructureLeaks(string $location, string $body, string $message): void
    {
        $path = str_starts_with($location, 'Kernel/') ? 'src/' . $location : 'src/Modules/' . $location;
        $namespace = 'RentalManager\\' . str_replace('/', '\\', substr($path, 4));
        $source = '<?php namespace ' . $namespace . '; class Probe { public function run() { ' . $body . ' } }';
        $errors = self::$inspector->inspect($source, $path . '/Probe.php');
        self::assertStringContainsString($message, implode("\n", $errors));
    }

    public function testResolvesAliasedFunctionCalls(): void
    {
        $source = '<?php namespace RentalManager\\Modules\\Units\\Domain;'
            . ' use function get_option as read_setting; class Probe { function run() { read_setting("x"); } }';
        self::assertStringContainsString('WordPress', implode("\n", self::$inspector->inspect(
            $source,
            'src/Modules/Units/Domain/Probe.php'
        )));
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedCode(): iterable
    {
        yield 'domain calculation' => [
            'Units/Domain', '$total = array_sum([1, 2]); // get_option and $wpdb are comments',
        ];
        yield 'own table adapter' => [
            'Units/Infrastructure', 'global $wpdb; $wpdb->get_results("SELECT * FROM rm_units");',
        ];
        yield 'read-only report' => [
            'Reporting/Infrastructure', 'global $wpdb; $wpdb->get_results("SELECT * FROM rm_units");',
        ];
        yield 'Kernel connection' => ['Kernel/Db', 'global $wpdb; $wpdb->query("SELECT 1");'];
        yield 'pure PHP exception' => ['Units/Domain', 'throw new \\RuntimeException("Invalid unit");'];
        yield 'English reporting prompt' => ['Reporting/Infrastructure', '$message = "Delete this filter";'];
        foreach (['Domain', 'Application', 'Api'] as $layer) {
            yield 'English prompt in ' . $layer => ['Units/' . $layer, '$message = "Select a unit from the list";'];
            yield 'plain translate in ' . $layer => ['Units/' . $layer, '$label = "translate";'];
        }
    }

    #[DataProvider('allowedCode')]
    public function testAllowsLegitimateCode(string $location, string $body): void
    {
        $path = str_starts_with($location, 'Kernel/') ? 'src/' . $location : 'src/Modules/' . $location;
        $namespace = 'RentalManager\\' . str_replace('/', '\\', substr($path, 4));
        $source = '<?php namespace ' . $namespace . '; class Probe { function run() { ' . $body . "\n } }";
        self::assertSame([], self::$inspector->inspect($source, $path . '/Probe.php'));
    }

    public function testRejectsNamespaceAndLayerBypasses(): void
    {
        self::assertNotEmpty(self::$inspector->inspect('<?php class Probe {}', 'src/Modules/Units/Domain/Probe.php'));
        self::assertNotEmpty(self::$inspector->inspect(
            '<?php namespace RentalManager\\Modules\\Units\\Infrastructure; class Probe {}',
            'src/Modules/Units/Domain/Probe.php'
        ));
        self::assertNotEmpty(self::$inspector->inspect('<?php class Probe {}', 'src/Modules/Unknown/Domain/Probe.php'));
    }
}
