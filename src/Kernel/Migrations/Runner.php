<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use Closure;
use Throwable;

final class Runner
{
    /** @var array<string, Migration> */
    private readonly array $migrations;

    /** @param array<string, mixed> $migrations */
    public function __construct(private readonly MigrationSession $session, array $migrations)
    {
        MigrationCatalog::validateIds(array_keys($migrations));
        ksort($migrations, SORT_STRING);
        $validated = [];
        foreach ($migrations as $id => $migration) {
            if (!$migration instanceof Migration) {
                throw new MigrationException('Migration ' . $id . ' must implement Migration.');
            }
            $validated[$id] = $migration;
        }
        $this->migrations = $validated;
    }

    /**
     * @param (Closure(): void)|null $after
     * @param (Closure(): bool)|null $before
     * @param (Closure(Throwable): void)|null $onFailure
     */
    public function run(?Closure $after = null, ?Closure $before = null, ?Closure $onFailure = null): void
    {
        $this->session->withLock(function () use ($after, $before, $onFailure): void {
            try {
                $this->apply($after, $before);
            } catch (Throwable $error) {
                try {
                    if ($onFailure !== null) {
                        $this->session->assertLock();
                        $onFailure($error);
                    }
                } finally {
                    throw $error;
                }
            }
        });
    }

    /**
     * @param (Closure(): void)|null $after
     * @param (Closure(): bool)|null $before
     */
    private function apply(?Closure $after, ?Closure $before): void
    {
        if ($before !== null && !$before()) {
            return;
        }
        $applied = $this->session->appliedMigrations();
        if ($applied !== array_slice(array_keys($this->migrations), 0, count($applied))) {
            throw new MigrationException('Applied migrations do not match the migration catalog.');
        }

        $schema = $this->session->schema();
        foreach (array_slice($this->migrations, count($applied)) as $id => $migration) {
            try {
                $this->session->markStarted($id);
            } catch (Throwable $error) {
                throw new MigrationException(
                    'Could not start migration ' . $id . '.',
                    (int) $error->getCode(),
                    migrationId: $id
                );
            }
            try {
                $migration->up($schema);
                $this->session->markApplied($id);
            } catch (Throwable $error) {
                throw new MigrationException(
                    'Migration ' . $id . ' stopped before completion. Schema reconciliation is required.',
                    (int) $error->getCode(),
                    migrationId: $id
                );
            }
        }
        if ($after !== null) {
            $this->session->assertLock();
            $after();
        }
    }
}
