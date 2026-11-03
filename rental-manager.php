<?php
/**
 * Plugin Name: Rental Manager
 * Description: Arabic-first rental management for property owners and renters.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: rental-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', static function () {
    load_plugin_textdomain('rental-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Keep this file compatible with older PHP: check before loading PHP 8.1 code.
$rmRequirementsMet = version_compare(PHP_VERSION, '8.1', '>=')
    && isset($GLOBALS['wp_version'])
    && version_compare($GLOBALS['wp_version'], '6.4', '>=');

if (!$rmRequirementsMet) {
    add_action('admin_notices', static function () {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__(
                'Rental Manager requires PHP 8.1 or later and WordPress 6.4 or later.',
                'rental-manager'
            );
            echo '</p></div>';
        }
    });
    return;
}

if (!is_readable(__DIR__ . '/vendor-prefixed/autoload.php')) {
    add_action('admin_notices', static function () {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__(
                'Rental Manager is missing its dependencies. Install a complete release or run composer install.',
                'rental-manager'
            );
            echo '</p></div>';
        }
    });
    return;
}

require_once __DIR__ . '/vendor-prefixed/autoload.php';

\RentalManager\Kernel\Kernel::boot();
