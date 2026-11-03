<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use WP_Hook;
use wpdb;

abstract class DatabaseTestCase extends TestCase
{
    protected wpdb $db;
    /** @var array<string, WP_Hook> */
    private array $hooks;
    /** @var array<string, int> */
    private array $actions;
    /** @var list<string> */
    private array $filters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $GLOBALS['wpdb'];
        $this->hooks = [];
        foreach ($GLOBALS['wp_filter'] as $name => $hook) {
            $this->hooks[$name] = clone $hook;
        }
        $this->actions = $GLOBALS['wp_actions'];
        $this->filters = $GLOBALS['wp_current_filter'];
        wp_set_current_user(0);
        FixtureSchema::query($this->db, 'START TRANSACTION');
    }

    protected function tearDown(): void
    {
        try {
            FixtureSchema::query($this->db, 'ROLLBACK');
            wp_cache_flush();
            wp_set_current_user(0);
            $GLOBALS['wp_filter'] = $this->hooks;
            $GLOBALS['wp_actions'] = $this->actions;
            $GLOBALS['wp_current_filter'] = $this->filters;
        } finally {
            parent::tearDown();
        }
    }
}
