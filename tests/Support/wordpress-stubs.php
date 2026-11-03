<?php

declare(strict_types=1);

// Function declarations are separate from the standalone probe's execution.
function add_action(string $hook, callable $callback): void
{
    $GLOBALS['hooks'][$hook][] = $callback;
}

function register_activation_hook(string $file, callable $callback): void
{
    $GLOBALS['activation_hook'] = $callback;
}

function deactivate_plugins(string $plugin): void
{
    $GLOBALS['deactivated'] = $plugin === 'rental-manager/rental-manager.php';
}

function plugin_basename(string $file): string
{
    return 'rental-manager/' . basename($file);
}

function load_plugin_textdomain(string $domain, bool $deprecated, string $path): bool
{
    return true;
}

function wp_die(string $message): void
{
    $GLOBALS['die_message'] = $message;
    throw new RuntimeException('Activation refused.');
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
