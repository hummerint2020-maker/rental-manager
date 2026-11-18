<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use Throwable;

final class MigrationCatalog
{
    /** @return array<string, Migration> */
    public static function load(string $directory): array
    {
        $entries = is_dir($directory) ? scandir($directory) : false;
        if ($entries === false) {
            throw new MigrationException('Migration directory cannot be read.');
        }

        $files = [];
        foreach ($entries as $name) {
            if (in_array($name, ['.', '..', '.gitkeep'], true)) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $id = substr($name, 0, -4);
            if (!str_ends_with($name, '.php') || !self::validId($id) || is_link($path) || !is_file($path)) {
                throw new MigrationException('Expected a numbered migration file.');
            }
            $files[$id] = $path;
        }
        self::validateIds(array_keys($files));
        ksort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $id => $file) {
            try {
                $migration = (static fn (string $path): mixed => require $path)($file);
            } catch (Throwable $error) {
                throw new MigrationException(
                    'Could not load migration ' . $id . '.',
                    (int) $error->getCode(),
                    migrationId: $id
                );
            }
            if (!$migration instanceof Migration) {
                throw new MigrationException('Migration ' . $id . ' must return a Migration.', migrationId: $id);
            }
            $migrations[$id] = $migration;
        }
        return $migrations;
    }

    /** @param list<int|string> $ids */
    public static function validateIds(array $ids): void
    {
        $numbers = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !self::validId($id)) {
                throw new MigrationException('Invalid migration identifier.');
            }
            $number = substr($id, 0, 4);
            if (isset($numbers[$number])) {
                throw new MigrationException('Duplicate migration number ' . $number . '.');
            }
            $numbers[$number] = true;
        }
    }

    private static function validId(string $id): bool
    {
        return !str_starts_with($id, '0000')
            && preg_match('/^[0-9]{4}_[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $id) === 1
            && strlen($id) <= 190;
    }
}
