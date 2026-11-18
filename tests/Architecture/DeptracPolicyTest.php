<?php

declare(strict_types=1);

namespace RentalManager\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RentalManager\Tests\Support\ArchitectureSandbox;

final class DeptracPolicyTest extends TestCase
{
    private ArchitectureSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = new ArchitectureSandbox();
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    public function testEveryCrossModuleApiDirection(): void
    {
        $modules = [
            'Platform', 'Identity', 'Settings', 'Units', 'Renters', 'Leasing', 'Billing',
            'Expenses', 'Maintenance', 'Documents', 'Notifications', 'Activity', 'Reporting',
        ];
        $allowed = [
            'Leasing' => ['Billing', 'Units', 'Renters'], 'Billing' => ['Documents'],
            'Expenses' => ['Billing', 'Documents'], 'Maintenance' => ['Expenses', 'Documents'],
            'Renters' => ['Identity'],
        ];
        foreach ($modules as $module) {
            $this->sandbox->writeClass('RentalManager\\Modules\\' . $module . '\\Api\\Gateway');
        }
        $expectations = [];
        foreach ($modules as $from) {
            foreach ($modules as $to) {
                if ($from === $to) {
                    continue;
                }
                $caller = 'RentalManager\\Modules\\' . $from . '\\Application\\Calls' . $to;
                $target = 'RentalManager\\Modules\\' . $to . '\\Api\\Gateway';
                $this->sandbox->writeClass($caller, $target);
                $expectations[$caller . ' must not depend on ' . $target] =
                    !in_array($to, ['Settings', 'Identity'], true) && !in_array($to, $allowed[$from] ?? [], true);
            }
        }
        $result = $this->sandbox->deptrac();
        self::assertSame(1, $result['status']);
        self::assertSame(0, $result['report']['Report']['Uncovered']);
        self::assertSame(0, $result['report']['Report']['Errors']);
        $messages = json_encode($result['report']['files'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Decode escaping to compare dependency names rather than JSON serialization.
        $messages = str_replace('\\\\', '\\', $messages);
        foreach ($expectations as $message => $forbidden) {
            if ($forbidden) {
                self::assertStringContainsString($message, $messages);
            } else {
                self::assertStringNotContainsString($message, $messages);
            }
        }
    }

    public function testLayerBoundariesAndKernelContracts(): void
    {
        $definitions = [
            'RentalManager\\Kernel\\Clock\\Clock' => true,
            'RentalManager\\Kernel\\Money\\Money' => false,
            'RentalManager\\Kernel\\Container\\Container' => false,
            'RentalManager\\Kernel\\Container\\ContainerInterface' => true,
            'RentalManager\\Kernel\\Db\\Connection' => false,
            'RentalManager\\Kernel\\Db\\UnitOfWork' => true,
            'RentalManager\\Kernel\\Db\\TransactionalUnitOfWork' => false,
            'RentalManager\\Kernel\\Migrations\\Migration' => true,
            'RentalManager\\Kernel\\Migrations\\Schema' => false,
            'RentalManager\\Kernel\\Http\\Router' => true,
            'RentalManager\\Kernel\\Storage\\Storage' => true,
            'RentalManager\\Kernel\\Tenancy\\AccountContext' => false,
            'RentalManager\\Kernel\\Errors\\DomainRuleException' => false,
            'RentalManager\\Kernel\\Errors\\NotFound' => false,
            'RentalManager\\Kernel\\Errors\\Conflict' => false,
            'RentalManager\\Modules\\Units\\Domain\\Unit' => false,
            'RentalManager\\Modules\\Units\\Application\\Repository' => true,
            'RentalManager\\Modules\\Units\\Infrastructure\\Repository' => false,
            'RentalManager\\Modules\\Billing\\Domain\\Charge' => false,
            'RentalManager\\Modules\\Billing\\Infrastructure\\Repository' => false,
            'RentalManager\\Modules\\Leasing\\Api\\Query\\CurrentContract' => true,
        ];
        foreach ($definitions as $name => $interface) {
            $this->sandbox->writeClass($name, null, $interface);
        }
        $edges = [
            ['Domain', 'Kernel\\Clock\\Clock', false],
            ['Domain', 'Kernel\\Money\\Money', false],
            ['Application', 'Kernel\\Clock\\Clock', false],
            ['Application', 'Modules\\Units\\Application\\Repository', false],
            ['Infrastructure', 'Modules\\Units\\Application\\Repository', false],
            ['Domain', 'Modules\\Units\\Infrastructure\\Repository', true],
            ['Application', 'Modules\\Units\\Infrastructure\\Repository', true],
            ['Application', 'Kernel\\Container\\Container', true],
            ['Application', 'Kernel\\Container\\ContainerInterface', true],
            ['Domain', 'Kernel\\Container\\ContainerInterface', true],
            ['Presentation', 'Modules\\Units\\Domain\\Unit', true],
            ['Infrastructure', 'Modules\\Billing\\Domain\\Charge', true],
            ['Api', 'Modules\\Billing\\Infrastructure\\Repository', true],
            ['Application', 'Modules\\Leasing\\Api\\Query\\CurrentContract', false],
            ['Domain', 'Kernel\\Container\\Container', true],
            ['Domain', 'Kernel\\Db\\Connection', true],
            ['Domain', 'Kernel\\Db\\UnitOfWork', true],
            ['Domain', 'Kernel\\Db\\TransactionalUnitOfWork', true],
            ['Domain', 'Kernel\\Http\\Router', true],
            ['Domain', 'Kernel\\Storage\\Storage', true],
            ['Application', 'Kernel\\Db\\UnitOfWork', false],
            ['Application', 'Kernel\\Db\\TransactionalUnitOfWork', true],
            ['Application', 'Kernel\\Migrations\\Migration', true],
            ['Application', 'Kernel\\Migrations\\Schema', true],
            ['Domain', 'Kernel\\Migrations\\Migration', true],
            ['Application', 'Kernel\\Http\\Router', false],
            ['Application', 'Kernel\\Storage\\Storage', false],
        ];
        foreach (['Api', 'Domain', 'Application', 'Infrastructure', 'Presentation'] as $layer) {
            $sharedTypes = [
                'Tenancy\\AccountContext', 'Errors\\DomainRuleException', 'Errors\\NotFound', 'Errors\\Conflict',
            ];
            foreach ($sharedTypes as $type) {
                $edges[] = [$layer, 'Kernel\\' . $type, false];
            }
        }
        $expected = [];
        foreach ($edges as $number => [$layer, $dependency, $forbidden]) {
            $caller = 'RentalManager\\Modules\\Units\\' . $layer . '\\Probe' . $number;
            $target = 'RentalManager\\' . $dependency;
            $this->sandbox->writeClass($caller, $target);
            $expected[$caller . ' must not depend on ' . $target] = $forbidden;
        }
        $result = $this->sandbox->deptrac();
        self::assertSame(1, $result['status']);
        self::assertSame(0, $result['report']['Report']['Uncovered']);
        $messages = str_replace('\\\\', '\\', json_encode($result['report']['files'], JSON_THROW_ON_ERROR));
        foreach ($expected as $message => $forbidden) {
            self::assertSame($forbidden, str_contains($messages, $message), $message);
        }
    }

    public function testDomainWpdbSampleFailsTheArchitectureCommandAndIsRemoved(): void
    {
        $relative = '/src/Modules/Units/Domain/Forbidden.php';
        mkdir(dirname($this->sandbox->directory . $relative), 0777, true);
        file_put_contents(
            $this->sandbox->directory . $relative,
            '<?php namespace RentalManager\\Modules\\Units\\Domain;'
            . ' class Forbidden { function run() { global $wpdb; $wpdb->query("SELECT 1"); } }'
        );
        [$status, $output] = $this->sandbox->run([
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-architecture.php', $this->sandbox->directory . '/src',
        ]);
        self::assertSame(1, $status);
        self::assertStringContainsString('$wpdb is restricted to Infrastructure', $output);
        unlink($this->sandbox->directory . $relative);
        [$cleanStatus] = $this->sandbox->run([
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-architecture.php', $this->sandbox->directory . '/src',
        ]);
        self::assertSame(0, $cleanStatus);
    }
}
