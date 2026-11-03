<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support;

use InvalidArgumentException;

final class TestAccount
{
    /** @param array<string, int> $users
     *  @param array<string, int> $memberships
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $renterId,
        public readonly array $users,
        public readonly array $memberships
    ) {
    }

    public function user(string $role): int
    {
        return $this->users[$role] ?? throw new InvalidArgumentException('Unknown fixture role.');
    }
}
