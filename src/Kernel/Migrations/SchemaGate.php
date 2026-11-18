<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use Throwable;

final class SchemaGate
{
    public const VERSION_OPTION = 'rm_schema_version';

    public function __construct(private readonly string $pluginFile)
    {
    }

    public function isReady(): bool
    {
        try {
            return $this->installedVersion() === $this->version() && get_option(FailureState::OPTION, null) === null;
        } catch (Throwable) {
            return false;
        }
    }

    public function installedVersion(): string
    {
        $installed = get_option(self::VERSION_OPTION, '');
        return is_string($installed) ? $installed : '';
    }

    public function version(): string
    {
        $header = get_file_data($this->pluginFile, ['Version' => 'Version']);
        $version = $header['Version'];
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            throw new MigrationException('Invalid plugin version.');
        }
        return $version;
    }
}
