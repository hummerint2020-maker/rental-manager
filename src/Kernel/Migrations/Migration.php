<?php

declare(strict_types=1);

namespace RentalManager\Kernel\Migrations;

interface Migration
{
    public function up(Schema $schema): void;
}
