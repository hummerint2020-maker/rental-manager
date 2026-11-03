<?php

declare(strict_types=1);

// Destructive only to the disposable CI database configured below. Never use on hosting.
if (getenv('CI') !== 'true' || getenv('RM_TEST_DB_PASSWORD') === false) {
    fwrite(STDERR, "This smoke check runs only against a disposable CI WordPress installation.\n");
    exit(1);
}

$wordpress = getenv('RM_TEST_WP_DIR');
if (!$wordpress || !is_file($wordpress . '/wp-settings.php') || is_file($wordpress . '/wp-config.php')) {
    fwrite(STDERR, "Provide a fresh WordPress directory in RM_TEST_WP_DIR (without wp-config.php).\n");
    exit(1);
}

require dirname(__DIR__) . '/tests/Support/wordpress-smoke-config.php';
$table_prefix = 'wp_';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
add_filter('pre_wp_mail', static fn () => false);

if (is_blog_installed()) {
    throw new RuntimeException('Refusing to run against an existing installation.');
}
wp_install('Rental Manager smoke test', 'smoke-admin', 'smoke@example.invalid', true, '', bin2hex(random_bytes(24)));

$result = activate_plugin('rental-manager/rental-manager.php');
if (is_wp_error($result) || !is_plugin_active('rental-manager/rental-manager.php')) {
    throw new RuntimeException(is_wp_error($result) ? $result->get_error_message() : 'Plugin did not activate.');
}

if (!class_exists(\RentalManager\Kernel\Kernel::class) || !class_exists(\RentalManager\Vendor\Mpdf\Mpdf::class)) {
    throw new RuntimeException('The isolated autoloader did not load the kernel and PDF library.');
}
if (class_exists('Mpdf\\Mpdf') || class_exists('PHPUnit\\Framework\\TestCase')) {
    throw new RuntimeException('The plugin loaded unprefixed vendor or development classes.');
}

if (!function_exists('as_enqueue_async_action') || !class_exists('ActionScheduler')) {
    throw new RuntimeException('The bundled, unprefixed Action Scheduler did not initialize.');
}

load_textdomain('rental-manager', WP_PLUGIN_DIR . '/rental-manager/languages/rental-manager-ar.mo');
$message = 'Rental Manager requires PHP 8.1 or later and WordPress 6.4 or later.';
if (translate($message, 'rental-manager') === $message) {
    throw new RuntimeException('Bundled Arabic translations did not load.');
}
echo "Clean WordPress 6.4 activation, isolated autoloading and Arabic translation passed.\n";
