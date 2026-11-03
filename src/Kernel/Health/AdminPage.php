<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

final class AdminPage
{
    public static function register(): void
    {
        $page = new self();
        add_action('admin_menu', [$page, 'menu']);
        SchedulerProbe::register();
    }

    public function menu(): void
    {
        add_menu_page(
            __('Rental Manager', 'rental-manager'),
            __('Rental Manager', 'rental-manager'),
            'manage_options',
            'rental-manager',
            [$this, 'render']
        );
        add_submenu_page(
            'rental-manager',
            __('System Health', 'rental-manager'),
            __('System Health', 'rental-manager'),
            'manage_options',
            'rental-manager-health',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to view System Health.', 'rental-manager'),
                '',
                ['response' => 403]
            );
        }
        $results = [];
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('rm_health_checks');
            $results = (new Checks())->run();
        }
        echo '<div class="wrap"><h1>' . esc_html__('System Health', 'rental-manager') . '</h1><p>';
        echo esc_html__(
            'Run hosting diagnostics. Temporary database tables and files are removed; '
                . 'a scheduler canary may stay pending.',
            'rental-manager'
        );
        echo '</p><form method="post">';
        wp_nonce_field('rm_health_checks');
        submit_button(__('Run checks', 'rental-manager'));
        echo '</form><table class="widefat"><thead><tr><th>';
        echo esc_html__('Check', 'rental-manager') . '</th><th>' . esc_html__('Status', 'rental-manager') . '</th><th>';
        echo esc_html__('Details', 'rental-manager') . '</th></tr></thead><tbody>';
        $statuses = [
            'pass' => __('Pass', 'rental-manager'),
            'fail' => __('Fail', 'rental-manager'),
            'pending' => __('Pending', 'rental-manager'),
        ];
        foreach ($results as $result) {
            echo '<tr><td>' . esc_html(__($result->message, 'rental-manager')) . '</td><td>';
            echo esc_html($statuses[$result->status]) . '</td><td>' . esc_html($result->detail) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
