<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use RentalManager\Kernel\Db\Connection;
use RentalManager\Kernel\Health\Result;
use Throwable;
use wpdb;

/** Passive diagnostics: never acquires an upgrade lock or changes migration history. */
final class Diagnostics
{
    private readonly wpdb $db;
    private readonly string $journal;

    public function __construct(
        private readonly string $pluginFile,
        ?wpdb $db = null,
        ?string $journal = null
    ) {
        $this->db = $db ?? $GLOBALS['wpdb'];
        $this->journal = $journal ?? $this->db->prefix . 'rm_migrations';
    }

    /** @return array{available: bool, incomplete: list<string>} */
    public function journal(): array
    {
        try {
            $connection = new Connection($this->db);
            if (
                $connection->fetchValue(
                    'SELECT COUNT(*) FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $this->journal
                ) !== '1'
            ) {
                return ['available' => false, 'incomplete' => []];
            }
            $rows = $connection->fetchAll(
                'SELECT id FROM %i WHERE applied_at IS NULL ORDER BY id',
                $this->journal
            );
            $ids = [];
            foreach ($rows as $row) {
                $id = $row['id'];
                if (!is_string($id)) {
                    return ['available' => false, 'incomplete' => []];
                }
                MigrationCatalog::validateIds([$id]);
                $ids[] = $id;
            }
            return ['available' => true, 'incomplete' => $ids];
        } catch (Throwable) {
            return ['available' => false, 'incomplete' => []];
        }
    }

    public function result(): Result
    {
        $header = get_file_data($this->pluginFile, ['Version' => 'Version']);
        $stored = get_option('rm_schema_version', '');
        $failure = FailureState::read();
        $journal = $this->journal();
        $detail = sprintf(
            __('Stored: %1$s; plugin: %2$s; failed migration: %3$s', 'rental-manager'),
            is_string($stored) && $stored !== '' ? $stored : __('Not installed', 'rental-manager'),
            $header['Version'],
            $failure['migration_id'] ?? __('None', 'rental-manager')
        );
        return new Result(
            'schema',
            (new SchemaGate($this->pluginFile))->isReady() && $journal['available'] && $journal['incomplete'] === []
                ? 'pass' : 'fail',
            'Database schema',
            $detail
        );
    }
}
