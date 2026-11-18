<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use Throwable;

final class FailureState
{
    public const OPTION = 'rm_migration_failure';

    /** @return array{version: string, migration_id: ?string, failed_at: string, error_code: int}|null */
    public static function read(): ?array
    {
        $value = get_option(self::OPTION, null);
        if (
            !is_array($value)
            || !is_string($value['version'] ?? null)
            || preg_match('/^(?:\d+\.\d+\.\d+|unknown)$/D', $value['version']) !== 1
            || !array_key_exists('migration_id', $value)
            || ($value['migration_id'] !== null && self::validId($value['migration_id']) === null)
            || !is_string($value['failed_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value['failed_at']) !== 1
            || !is_int($value['error_code'] ?? null)
        ) {
            return null;
        }
        return [
            'version' => $value['version'],
            'migration_id' => self::validId($value['migration_id']),
            'failed_at' => $value['failed_at'],
            'error_code' => $value['error_code'],
        ];
    }

    public static function record(string $version, Throwable $error): void
    {
        update_option(self::OPTION, [
            'version' => preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1 ? $version : 'unknown',
            'migration_id' => $error instanceof MigrationException ? self::validId($error->migrationId()) : null,
            'failed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'error_code' => (int) $error->getCode(),
        ], false);
        // update_option returns early for unchanged values; enforce autoload off independently.
        wp_set_option_autoload(self::OPTION, false);
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    private static function validId(mixed $id): ?string
    {
        if (!is_string($id)) {
            return null;
        }
        try {
            MigrationCatalog::validateIds([$id]);
            return $id;
        } catch (MigrationException) {
            return null;
        }
    }
}
