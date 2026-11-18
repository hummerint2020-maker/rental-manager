<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use Closure;
use Throwable;
use wpdb;

/** WordPress lifecycle adapter; schema changes never run inside a financial UoW. */
final class Lifecycle
{
    /** @param null|Closure(): MigrationSession $sessionFactory */
    public function __construct(
        private readonly string $pluginFile,
        private readonly ?Closure $sessionFactory = null,
        private readonly ?bool $cli = null
    ) {
    }

    public function register(): void
    {
        add_action('init', $this->onInit(...), 1);
    }

    public function onInit(): void
    {
        $this->attempt(false);
    }

    public function retry(): void
    {
        $this->attempt(true);
    }

    public function version(): string
    {
        return (new SchemaGate($this->pluginFile))->version();
    }

    public function activate(): void
    {
        $this->attempt(false, true);
    }

    public function migrate(bool $activation = false): void
    {
        $version = $this->version();
        if (!$activation && get_option(SchemaGate::VERSION_OPTION, '') === $version) {
            return;
        }
        $this->run($version, $activation);
    }

    private function attempt(bool $retry, bool $activation = false): void
    {
        $version = 'unknown';
        $force = $activation || $retry;
        $recorded = new class () {
            public bool $handled = false;
        };
        try {
            $version = $this->version();
            if (
                !$force && (get_option(SchemaGate::VERSION_OPTION, '') === $version
                    || (FailureState::read()['version'] ?? null) === $version)
            ) {
                return;
            }
            $this->run($version, $force, $retry, static function (Throwable $error) use ($version, $recorded): void {
                self::recordFailure($version, $error, true);
                $recorded->handled = true;
            });
        } catch (MigrationLockBusy $error) {
            if ($activation) {
                $this->reportActivationFailure($error);
            }
        } catch (Throwable $error) {
            if (!$recorded->handled) {
                try {
                    self::recordFailure($version, $error, $force);
                } catch (Throwable) {
                    // Failure reporting cannot take a normal WordPress request offline.
                }
            }
            if ($activation) {
                $this->reportActivationFailure($error);
            } elseif ($this->isCli()) {
                $this->throwCliFailure($error);
            }
        }
    }

    /** @param (Closure(Throwable): void)|null $onFailure */
    private function run(string $version, bool $force, bool $retry = false, ?Closure $onFailure = null): void
    {
        /** @var wpdb $db */
        $db = $GLOBALS['wpdb'];
        $session = $this->sessionFactory !== null
            ? ($this->sessionFactory)() : new MigrationSession($db, $db->prefix . 'rm_');
        $migrations = MigrationCatalog::load(dirname($this->pluginFile) . '/migrations');
        $readVersion = static fn (): ?string => $session->fetchValue(
            'SELECT option_value FROM %i WHERE option_name = %s',
            $db->options,
            SchemaGate::VERSION_OPTION
        );
        (new Runner($session, $migrations))->run(
            static function () use ($session, $db, $version): void {
                $session->execute(
                    'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) '
                    . 'ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
                    $db->options,
                    SchemaGate::VERSION_OPTION,
                    $version,
                    'off'
                );
                FailureState::clear();
                self::clearOptionCache();
            },
            static function () use ($readVersion, $version, $force, $retry, $onFailure): bool {
                if ($onFailure !== null) {
                    self::clearOptionCache();
                    if (!$force && (FailureState::read()['version'] ?? null) === $version) {
                        return false;
                    }
                }
                $installed = $readVersion();
                if ($installed !== null && $installed !== '' && version_compare($installed, $version, '>')) {
                    throw new MigrationException('Database schema belongs to a newer plugin version.');
                }
                if ($retry) {
                    FailureState::clear();
                }
                if (!$force && $installed === $version) {
                    self::clearOptionCache();
                    return false;
                }
                return true;
            },
            $onFailure
        );
    }

    private static function clearOptionCache(): void
    {
        wp_cache_delete(SchemaGate::VERSION_OPTION, 'options');
        wp_cache_delete(FailureState::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private static function recordFailure(string $version, Throwable $error, bool $recordCurrent): void
    {
        self::clearOptionCache();
        $installed = get_option(SchemaGate::VERSION_OPTION, '');
        // A concurrent successful run may have updated the version after a pre-lock failure.
        if (
            is_string($installed)
            && $installed !== ''
            && (version_compare($installed, $version, '>') || (!$recordCurrent && $installed === $version))
        ) {
            return;
        }
        FailureState::record($version, $error);
    }

    private function isCli(): bool
    {
        return $this->cli ?? PHP_SAPI === 'cli';
    }

    private function throwCliFailure(Throwable $error): never
    {
        throw new MigrationException(
            'Rental Manager could not update its database.',
            (int) $error->getCode(),
            $error,
            $error instanceof MigrationException ? $error->migrationId() : null
        );
    }

    private function reportActivationFailure(Throwable $error): void
    {
        deactivate_plugins(plugin_basename($this->pluginFile));
        load_plugin_textdomain('rental-manager', false, dirname(plugin_basename($this->pluginFile)) . '/languages');
        if ($this->isCli()) {
            $this->throwCliFailure($error);
        }
        wp_die(
            esc_html__(
                'Rental Manager could not update its database. Ask the administrator to check the migration journal.',
                'rental-manager'
            ),
            '',
            ['response' => 503]
        );
    }
}
