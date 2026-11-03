<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Health;

final class SchedulerProbe
{
    public static function register(): void
    {
        add_action('rm_health_canary', static function (string $token): void {
            if (preg_match('/^[a-f0-9]{32}$/D', $token)) {
                set_transient('rm_health_ack_' . $token, 'done', 300);
            }
        });
    }

    public function run(): Result
    {
        if (!function_exists('as_enqueue_async_action') || !did_action('action_scheduler_init')) {
            return new Result('scheduler', 'fail', 'Action Scheduler canary execution');
        }
        $key = 'rm_health_runner_' . get_current_user_id();
        $state = get_transient($key);
        if (
            !is_array($state) || !isset($state['token'], $state['started'])
            || !is_string($state['token']) || !preg_match('/^[a-f0-9]{32}$/D', $state['token'])
            || !is_int($state['started'])
        ) {
            $token = bin2hex(random_bytes(16));
            $id = as_enqueue_async_action('rm_health_canary', [$token], 'rm-health', true);
            if (!$id) {
                return new Result('scheduler', 'fail', 'Action Scheduler canary execution');
            }
            $state = ['token' => $token, 'started' => time()];
            set_transient($key, $state, 300);
        }
        $done = get_transient('rm_health_ack_' . $state['token']) === 'done';
        if ($done) {
            return new Result('scheduler', 'pass', 'Action Scheduler canary execution');
        }
        if (time() - $state['started'] >= 120) {
            as_unschedule_action('rm_health_canary', [$state['token']], 'rm-health');
            return new Result('scheduler', 'fail', 'Action Scheduler canary timed out');
        }
        return new Result('scheduler', 'pending', 'Canary queued; run checks again within two minutes');
    }
}
