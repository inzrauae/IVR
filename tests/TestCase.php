<?php

namespace Tests;

use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles, permissions and packages after each RefreshDatabase migration.
     */
    protected bool $seed = true;

    protected string $seeder = BaseDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
