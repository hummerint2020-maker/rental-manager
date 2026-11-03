<?php

declare(strict_types=1);

// Standalone process: never load real WordPress or the test runner's autoloader.
$root = $argv[1];
$GLOBALS['wp_version'] = $argv[2];
$GLOBALS['can_manage'] = $argv[3] === 'admin';
$GLOBALS['hooks'] = [];
$GLOBALS['booted'] = false;

if ($argv[4] !== 'direct') {
    define('ABSPATH', __DIR__ . '/');
}

function add_action(string $hook, callable $callback): void
{
    $GLOBALS['hooks'][$hook][] = $callback;
}

function current_user_can(string $capability): bool
{
    return $capability === 'manage_options' && $GLOBALS['can_manage'];
}

function esc_html__(string $message, string $domain): string
{
    if ($domain !== 'rental-manager') {
        throw new RuntimeException('Incorrect text domain.');
    }
    return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
}

require $root . '/rental-manager.php';

ob_start();
foreach ($GLOBALS['hooks']['admin_notices'] ?? [] as $callback) {
    $callback();
}
$notice = ob_get_clean();

echo json_encode(['booted' => $GLOBALS['booted'], 'notice' => $notice], JSON_THROW_ON_ERROR);
