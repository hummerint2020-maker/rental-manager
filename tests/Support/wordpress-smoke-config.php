<?php

declare(strict_types=1);

// Loaded only after the smoke runner validates its disposable CI environment.
define('ABSPATH', rtrim((string) getenv('RM_TEST_WP_DIR'), '/\\') . '/');
define('WP_INSTALLING', true);
define('DB_NAME', 'rental_manager_test');
define('DB_USER', 'root');
define('DB_PASSWORD', (string) getenv('RM_TEST_DB_PASSWORD'));
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_520_ci');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', true);
define('WP_HOME', 'http://localhost');
define('WP_SITEURL', 'http://localhost');
define('DISABLE_WP_CRON', true);
