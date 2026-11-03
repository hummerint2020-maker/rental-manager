<?php

declare(strict_types=1);

// Standalone process: never load real WordPress or the test runner's autoloader.
$root = $argv[1];
$GLOBALS['wp_version'] = $argv[2];
$GLOBALS['can_manage'] = $argv[3] === 'admin';
$GLOBALS['hooks'] = [];
$GLOBALS['booted'] = false;
$GLOBALS['scheduler_loads'] = 0;
$GLOBALS['plugins_loaded'] = false;
$GLOBALS['deactivated'] = false;
$GLOBALS['die_message'] = null;

require __DIR__ . '/wordpress-stubs.php';

if ($argv[4] !== 'direct') {
    require __DIR__ . '/wordpress-entry.php';
}

require $root . '/rental-manager.php';

if ($argv[4] === 'repeat') {
    require $root . '/rental-manager.php';
}

if ($argv[4] === 'activate') {
    try {
        ($GLOBALS['activation_hook'])();
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Activation refused.') {
            throw $exception;
        }
    }
}

ob_start();
foreach ($GLOBALS['hooks']['admin_notices'] ?? [] as $callback) {
    $callback();
}
$notice = ob_get_clean();

echo json_encode([
    'booted' => $GLOBALS['booted'],
    'notice' => $notice,
    'scheduler_loads' => $GLOBALS['scheduler_loads'],
    'deactivated' => $GLOBALS['deactivated'],
    'die_message' => $GLOBALS['die_message'],
], JSON_THROW_ON_ERROR);
