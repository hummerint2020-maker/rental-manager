<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use Closure;
use Throwable;
use wpdb;

/** WordPress lifecycle adapter; schema changes never run inside a financial UoW. */
final class Lifecycle
{
    private const OPTION = 'rm_schema_version';

    /** @param null|Closure(): MigrationSession $sessionFactory */
    public function __construct(private readonly string $pluginFile, private readonly ?Closure $sessionFactory = null)
    {
    }

    public function register(): void
    {
        add_action('init', function (): void {
            try {
                $this->migrate();
            } catch (Throwable $error) {
                $this->reportFailure($error);
            }
        }, 1);
    }

    public function activate(): void
    {
        try {
            $this->migrate(true);
        } catch (Throwable $error) {
            deactivate_plugins(plugin_basename($this->pluginFile));
            $this->reportFailure($error);
        }
    }

    public function migrate(bool $activation = false): void
    {
        $header = get_file_data($this->pluginFile, ['Version' => 'Version']);
        $version = $header['Version'];
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            throw new MigrationException('Invalid plugin version.');
        }
        if (!$activation && get_option(self::OPTION, '') === $version) {
            return;
        }
        /** @var wpdb $db */
        $db = $GLOBALS['wpdb'];
        $session = $this->sessionFactory !== null
            ? ($this->sessionFactory)() : new MigrationSession($db, $db->prefix . 'rm_');
        $migrations = MigrationCatalog::load(dirname($this->pluginFile) . '/migrations');
        $readVersion = static fn (): ?string => $session->fetchValue(
            'SELECT option_value FROM %i WHERE option_name = %s',
            $db->options,
            self::OPTION
        );
        (new Runner($session, $migrations))->run(
            static function () use ($session, $db, $version): void {
                $session->execute(
                    'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) '
                    . 'ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
                    $db->options,
                    self::OPTION,
                    $version,
                    'off'
                );
                self::clearVersionCache();
            },
            static function () use ($readVersion, $version, $activation): bool {
                $installed = $readVersion();
                if ($installed !== null && $installed !== '' && version_compare($installed, $version, '>')) {
                    throw new MigrationException('Database schema belongs to a newer plugin version.');
                }
                if (!$activation && $installed === $version) {
                    self::clearVersionCache();
                    return false;
                }
                return $activation || $installed !== $version;
            }
        );
    }

    private static function clearVersionCache(): void
    {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private function reportFailure(Throwable $error): void
    {
        load_plugin_textdomain('rental-manager', false, dirname(plugin_basename($this->pluginFile)) . '/languages');
        if (PHP_SAPI === 'cli') {
            // WordPress's HTML wp_die handler exits with status zero even for HTTP 503.
            throw new MigrationException(
                'Rental Manager could not update its database.',
                (int) $error->getCode(),
                $error
            );
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
