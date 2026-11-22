<?php

declare(strict_types=1);

namespace Liberu\PackageTestbench;

use Tests\TestCase;

if (! trait_exists(UsesTestUser::class, false)) {
    trait UsesTestUser {}
}

if (! class_exists(PackageTestCase::class, false)) {
    abstract class PackageTestCase extends TestCase
    {
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
