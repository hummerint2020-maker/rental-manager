<?php

declare(strict_types=1);

namespace RentalManager\Tests\Integration;

use RentalManager\Tests\Support\DatabaseTestCase;
use RuntimeException;

final class MinimumWordPressTest extends DatabaseTestCase
{
    public function testSixXActivationIsRefusedWithTheBundledArabicMessage(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $file = dirname(__DIR__, 2) . '/rental-manager.php';
        $plugin = plugin_basename($file);
        $version = $GLOBALS['wp_version'];
        $locale = static fn (): string => 'ar';
        add_filter('locale', $locale);
        unload_textdomain('rental-manager', true);
        load_textdomain('rental-manager', dirname(__DIR__, 2) . '/languages/rental-manager-ar.mo', 'ar');
        add_filter('wp_die_handler', static fn (): callable => static function ($message): void {
            throw new RuntimeException((string) $message);
        });
        try {
            $GLOBALS['wp_version'] = '6.9.99';
            update_option('active_plugins', [$plugin]);
            require $file;
            try {
                do_action('activate_' . $plugin);
                self::fail('The plugin activation hook must reject WordPress 6.x.');
            } catch (RuntimeException $error) {
                self::assertSame(
                    'يتطلب مدير الإيجارات PHP بإصدار 8.1 أو أحدث ووردبريس بإصدار 7.0 أو أحدث.',
                    $error->getMessage()
                );
                self::assertNotContains($plugin, get_option('active_plugins', []));
            }
        } finally {
            $GLOBALS['wp_version'] = $version;
            remove_filter('locale', $locale);
            unload_textdomain('rental-manager', true);
        }
    }
}
