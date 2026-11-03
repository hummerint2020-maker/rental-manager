<?php

declare(strict_types=1);

namespace RentalManager\Tests\Api;

use RentalManager\Tests\Support\DatabaseTestCase;
use RentalManager\Tests\Support\World;
use WP_REST_Request;

final class ApiHarnessTest extends DatabaseTestCase
{
    public function testRealRestServerUsesTheFixtureUser(): void
    {
        $world = new World($this->db);
        wp_set_current_user($world->a->user('owner'));
        $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users/me'));
        self::assertSame(200, $response->get_status());
        self::assertSame($world->a->user('owner'), $response->get_data()['id']);
        self::assertNotSame($world->b->user('owner'), $response->get_data()['id']);
    }
}
