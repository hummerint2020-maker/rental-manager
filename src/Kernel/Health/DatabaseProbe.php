<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

use RuntimeException;
use Throwable;
use wpdb;

/** Disposable diagnostic tables only; this is not a business-schema migration. */
final class DatabaseProbe
{
    public function __construct(private readonly wpdb $db)
    {
    }

    /** @return list<Result> */
    public function run(): array
    {
        $db = $this->db;
        $previous = $db->suppress_errors(true);
        $tables = [];
        $fk = false;
        $rollback = false;
        $clean = true;
        $version = (string) $db->get_var('SELECT VERSION()');
        $engine = (string) $db->get_var('SELECT @@default_storage_engine');
        try {
            if (!preg_match('/^[a-z0-9_]+$/iD', $db->prefix)) {
                throw new RuntimeException('Invalid database prefix.');
            }
            $parent = substr($db->prefix, 0, 20) . 'rm_health_' . bin2hex(random_bytes(8));
            $child = $parent . '_fk';
            $this->query("CREATE TABLE `$parent` (id INT PRIMARY KEY) ENGINE=InnoDB "
                . 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
            $tables[] = $parent;
            $this->query("CREATE TABLE `$child` (id INT PRIMARY KEY, parent_id INT NOT NULL, "
                . "FOREIGN KEY (parent_id) REFERENCES `$parent` (id)) ENGINE=InnoDB "
                . 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
            $tables[] = $child;
            $rejected = $db->query("INSERT INTO `$child` (id, parent_id) VALUES (1, 999)") === false;
            $warnings = $db->get_results('SHOW WARNINGS', ARRAY_A);
            foreach ($warnings as $warning) {
                $fk = $fk || ($rejected && (int) $warning['Code'] === 1452);
            }
            $this->query('START TRANSACTION');
            $this->query("INSERT INTO `$parent` (id) VALUES (1)");
            $inserted = (int) $db->get_var("SELECT COUNT(*) FROM `$parent`") === 1;
            $this->query('ROLLBACK');
            $rollback = $inserted && (int) $db->get_var("SELECT COUNT(*) FROM `$parent`") === 0;
        } catch (Throwable $error) {
            // Report failures without exposing database credentials, SQL errors or hosting paths.
        } finally {
            $clean = $db->query('ROLLBACK') !== false;
            foreach (array_reverse($tables) as $table) {
                $clean = ($db->query("DROP TABLE `$table`") !== false) && $clean;
            }
            $db->suppress_errors($previous);
        }
        return array_merge(Checks::databaseInformation($version), [
            Checks::result('engine', strcasecmp($engine, 'InnoDB') === 0, 'Default storage engine is InnoDB', $engine),
            Checks::result(
                'foreign_keys',
                $fk && $clean,
                'Foreign keys reject invalid references and probe tables are removed'
            ),
            Checks::result('rollback', $rollback && $clean, 'Transaction rollback removes the probe row'),
        ]);
    }

    private function query(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Database diagnostic failed.');
        }
    }
}
