<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

use RentalManager\Kernel\Health\Result;

/** Recovery remains reachable even while the plugin's business surfaces are gated. */
final class AdminRecovery
{
    private readonly Lifecycle $lifecycle;
    private readonly Diagnostics $diagnostics;
    private readonly string $pluginFile;

    public function __construct(?Lifecycle $lifecycle = null, ?Diagnostics $diagnostics = null)
    {
        $this->pluginFile = dirname(__DIR__, 3) . '/rental-manager.php';
        $this->lifecycle = $lifecycle ?? new Lifecycle($this->pluginFile);
        $this->diagnostics = $diagnostics ?? new Diagnostics($this->pluginFile);
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'notice']);
        add_action('admin_post_rm_retry_migrations', [$this, 'retry']);
    }

    public function result(): Result
    {
        return $this->diagnostics->result();
    }

    public function notice(): void
    {
        if (!current_user_can('manage_options') || (new SchemaGate($this->pluginFile))->isReady()) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Rental Manager is waiting for a database upgrade.', 'rental-manager') . ' ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=rental-manager-health')) . '">';
        echo esc_html__('Open System Health', 'rental-manager') . '</a></p></div>';
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $journal = $this->diagnostics->journal();
        echo '<h2>' . esc_html__('Migration recovery', 'rental-manager') . '</h2>';
        if (!$journal['available']) {
            echo '<p>' . esc_html__(
                'The journal could not be read. Check database access before retrying.',
                'rental-manager'
            ) . '</p>';
        }
        if ($journal['incomplete'] !== []) {
            echo '<h3>' . esc_html__('Incomplete migrations', 'rental-manager') . '</h3><ul>';
            foreach ($journal['incomplete'] as $id) {
                echo '<li><code>' . esc_html($id) . '</code></li>';
            }
            echo '</ul><p>' . esc_html__(
                'An incomplete migration must be reconciled with the actual schema before retrying. '
                    . 'Verify every effect, then reconcile its journal entry; never delete history blindly.',
                'rental-manager'
            ) . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="rm_retry_migrations">';
        wp_nonce_field('rm_retry_migrations');
        submit_button(__('Retry migrations', 'rental-manager'), 'secondary');
        echo '</form>';
    }

    public function retry(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to retry migrations.', 'rental-manager'), '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Retry migrations requires POST.', 'rental-manager'), '', ['response' => 405]);
        }
        check_admin_referer('rm_retry_migrations');
        $this->lifecycle->retry();
        wp_safe_redirect(admin_url('admin.php?page=rental-manager-health'));
    }
}
