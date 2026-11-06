<?php

declare(strict_types=1);

use RentalManager\Tests\Support\FixtureSchema;
use RentalManager\Tests\Support\WordPressTestInstaller;

require dirname(__DIR__) . '/vendor/autoload.php';
global $wpdb, $current_site, $current_blog, $wp_rewrite, $shortcode_tags, $wp, $phpmailer, $wp_theme_directories;
global $table_prefix;
$tests = getenv('WP_TESTS_DIR') ?: sys_get_temp_dir() . '/rm-wp-tests-' . (getenv('WP_VERSION') ?: '7.1.4');
$config = $tests . '/wp-tests-config.php';
if (!is_file($config) || !is_file($tests . '/includes/functions.php')) {
    throw new RuntimeException('WordPress test library missing. Run bin/install-wp-tests.sh; see docs/TESTING.md.');
}
require $config;
WordPressTestInstaller::assertDisposable(DB_NAME);
if (!is_readable(dirname(__DIR__) . '/vendor-prefixed/autoload.php')) {
    throw new RuntimeException('Build the isolated plugin dependencies on Linux/WSL before integration tests.');
}

// WordPress 7.1.4 still calls the removed PHPUnit parseTestMethodAnnotations API.
// Reuse its installer/helpers/factories without loading that compatibility layer.
$process = proc_open([PHP_BINARY, $tests . '/includes/install.php', $config], [1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process) || proc_close($process) !== 0) {
    throw new RuntimeException('Official WordPress test installation failed.');
}
require_once $tests . '/includes/functions.php';
tests_reset__SERVER();
tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/rental-manager.php';
});
tests_add_filter('pre_wp_mail', static fn () => false);
require ABSPATH . 'wp-settings.php';
require_once $tests . '/includes/factory.php';
add_role('rm_client', 'Rental Manager client', ['read' => true]);
FixtureSchema::create($GLOBALS['wpdb']);
