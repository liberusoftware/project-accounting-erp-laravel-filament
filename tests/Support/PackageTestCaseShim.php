<?php

declare(strict_types=1);

namespace Liberu\PackageTestbench;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Accounting\Erp\Models\User;
use Tests\TestCase;

if (! class_exists(TestUser::class, false)) {
    class TestUser extends User
    {
        protected $table = 'users';

        protected static function newFactory(): Factory
        {
            return User::factory();
        }
    }
}

if (! trait_exists(UsesTestUser::class, false)) {
    trait UsesTestUser {}
}

if (! class_exists(PackageTestCase::class, false)) {
    abstract class PackageTestCase extends TestCase
    {
        use RefreshDatabase;

        protected function defineEnvironment($app): void {}

        /**
         * @param  mixed  $app
         * @return array<int, class-string>
         */
        protected function getPackageProviders($app): array
        {
            return [];
        }
    }
}
