<?php

namespace Liberu\Accounting\Erp\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Liberu\Accounting\Erp\Models\Role;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $configuredPassword = config('app.seed_admin_password');
        $adminPassword = (string) ($configuredPassword ?: Str::random(64));
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make($adminPassword),
                'email_verified_at' => now(),
            ],
        );

        if (filled($configuredPassword) && ! $adminUser->wasRecentlyCreated) {
            $adminUser->forceFill(['password' => Hash::make((string) $configuredPassword)])->save();
        }

        $team = Team::firstOrFail();
        $adminUser->forceFill(['current_team_id' => $team->id])->save();
        $adminUser->teams()->syncWithoutDetaching([$team->id]);

        setPermissionsTeamId($team->id);
        $role = Role::where('name', 'super_admin')->firstOrFail();
        $adminUser->assignRole($role);

        // Print passwords to console
        if (app()->environment('local') && $adminUser->wasRecentlyCreated && blank(config('app.seed_admin_password'))) {
            $this->command?->info("Admin password: {$adminPassword}");
        }
    }
}
