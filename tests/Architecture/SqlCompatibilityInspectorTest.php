<?php

declare(strict_types=1);

namespace RentalManager\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RentalManager\Architecture\SqlCompatibilityInspector;
use RentalManager\Tests\Support\ArchitectureSandbox;

final class SqlCompatibilityInspectorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function forbidden(): iterable
    {
        yield 'CTE' => ['WITH recent AS (SELECT id FROM rm_units) SELECT * FROM recent', 'CTEs'];
        yield 'recursive CTE' => ['with recursive `tree` (id) as (select 1) select * from tree', 'CTEs'];
        yield 'window' => ['SELECT ROW_NUMBER() OVER (ORDER BY id) FROM rm_units', 'window functions'];
        yield 'named window' => ['SELECT SUM(id) OVER w FROM rm_units WINDOW w AS (ORDER BY id)', 'window functions'];
        yield 'skip locked' => ['SELECT id FROM rm_units FOR UPDATE SKIP /* comment */ LOCKED', 'SKIP LOCKED'];
        yield 'nowait' => ['SELECT id FROM rm_units FOR UPDATE NOWAIT', 'NOWAIT'];
        yield 'check' => ['CREATE TABLE t (id INT, CONSTRAINT positive CHECK (id > 0))', 'CHECK constraints'];
        yield 'alter check' => ['ALTER TABLE t ADD CHECK(id > 0)', 'CHECK constraints'];
        yield 'schema fragment' => ['id INT, CHECK (id > 0)', 'CHECK constraints'];
        yield 'descending index' => ['CREATE INDEX newest ON t (created_at DESC)', 'descending indexes'];
        yield 'descending table key' => ['CREATE TABLE t (id INT, KEY newest (id DESC))', 'descending indexes'];
        yield 'descending prefix key' => ['ALTER TABLE t ADD KEY newest (name(10) DESC)', 'descending indexes'];
        yield 'JSON table' => ['SELECT * FROM JSON_TABLE(data, "$[*]" COLUMNS(id INT PATH "$.id")) AS j', 'JSON_TABLE'];
        yield 'insert values alias' => [
            'INSERT INTO t (id) VALUES (1), (2) AS new ON DUPLICATE KEY UPDATE id = new.id', 'INSERT row aliases',
        ];
        yield 'insert set alias' => [
            'INSERT INTO t SET id = 1 AS new ON DUPLICATE KEY UPDATE id = new.id', 'INSERT row aliases',
        ];
        yield 'invisible index' => ['CREATE INDEX hidden ON t (id) INVISIBLE', 'invisible indexes'];
        yield 'alter invisible index' => ['ALTER TABLE t ALTER INDEX hidden INVISIBLE', 'invisible indexes'];
        yield '0900 collation' => ['CREATE TABLE t (id INT) COLLATE=utf8mb4_0900_ai_ci', 'utf8mb4_0900 collations'];
        yield 'quoted collation' => ['ALTER TABLE t COLLATE `utf8mb4_0900_as_cs`', 'utf8mb4_0900 collations'];
        yield 'conditional SQL comment' => ['SELECT id FROM t FOR UPDATE /*!80000 SKIP LOCKED */', 'SKIP LOCKED'];
        yield 'multi statement file' => ['SELECT 1; INSERT INTO t VALUES (1) AS new', 'INSERT row aliases'];
    }

    #[DataProvider('forbidden')]
    public function testRejectsEachForbiddenFeatureInPhpSqlFixtures(string $sql, string $feature): void
    {
        $source = '<?php $sql = ' . var_export($sql, true) . ';';
        $errors = (new SqlCompatibilityInspector())->inspectPhp($source, 'src/Kernel/Db/Fixture.php');
        self::assertStringContainsString('src/Kernel/Db/Fixture.php:1:', implode("\n", $errors));
        self::assertStringContainsString($feature, implode("\n", $errors));
    }

    /** @return iterable<string, array{string}> */
    public static function supported(): iterable
    {
        yield 'account scoped lock' => ['SELECT * FROM rm_units WHERE account_id = %d AND id = %d FOR UPDATE'];
        yield 'descending results' => ['SELECT * FROM rm_units WHERE account_id = %d ORDER BY id DESC LIMIT 25'];
        yield 'normal table' => [
            'CREATE TABLE t (id INT, KEY ascending_id (id ASC)) ENGINE=InnoDB '
                . 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
        ];
        yield 'JSON and generated columns' => ['CREATE TABLE t (data JSON, id INT AS (JSON_EXTRACT(data, "$.id")))'];
        yield 'insert select aliases' => ['INSERT INTO t (id) SELECT id AS identifier FROM other'];
        yield 'upsert' => ['INSERT INTO t (id) VALUES (1) ON DUPLICATE KEY UPDATE id = VALUES(id)'];
        yield 'string literals' => [
            "SELECT 'NOWAIT CHECK (x) JSON_TABLE(x) utf8mb4_0900_ai_ci', \"SKIP LOCKED\" FROM t",
        ];
        yield 'comments' => ["SELECT id /* OVER (x) */ FROM t -- NOWAIT\n# SKIP LOCKED\n"];
        yield 'quoted identifiers' => ['SELECT `nowait`, `invisible`, `check`, `over` FROM t'];
        yield 'explain normal' => ['EXPLAIN SELECT * FROM t ORDER BY id DESC'];
    }

    #[DataProvider('supported')]
    public function testAllowsNormalSqlAndIgnoresLiteralsAndComments(string $sql): void
    {
        self::assertSame([], (new SqlCompatibilityInspector())->violations($sql));
    }

    public function testReconstructsConcatenationInterpolationAndHeredocWithoutExecutingPhp(): void
    {
        $source = <<<'PHP'
<?php
$sql = "WI" . "TH cte AS (SELECT id FROM {$table}) SELECT * FROM cte";
$window = <<<SQL
SELECT SUM(amount) OVER (PARTITION BY account_id) FROM {$table}
SQL;
PHP;
        $errors = (new SqlCompatibilityInspector())->inspectPhp($source, 'fixture.php');
        self::assertCount(2, $errors);
        self::assertStringContainsString('CTEs', $errors[0]);
        self::assertStringContainsString('window functions', $errors[1]);
    }

    public function testCliGateFailsForBadPhpAndSqlAndPassesAfterRemovingTheFeature(): void
    {
        $sandbox = new ArchitectureSandbox();
        try {
            $file = $sandbox->directory . '/src/probe.php';
            file_put_contents($file, '<?php $sql = "WITH cte AS (SELECT 1) SELECT * FROM cte";');
            $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-sql.php', $sandbox->directory . '/src'];
            [$status, $output] = $sandbox->run($command);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('CTEs', $output);
            unlink($file);
            file_put_contents($sandbox->directory . '/src/probe.sql', 'SELECT ROW_NUMBER() OVER () FROM t');
            [$status, $output] = $sandbox->run($command);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('window functions', $output);
            file_put_contents($sandbox->directory . '/src/probe.sql', 'SELECT id FROM t ORDER BY id DESC');
            [$status, $output] = $sandbox->run($command);
            self::assertSame(0, $status, $output);
        } finally {
            $sandbox->remove();
        }
    }
}
