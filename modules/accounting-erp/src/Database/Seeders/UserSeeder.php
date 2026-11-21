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
        $configuredPassword = (string) config('app.seed_admin_password');
        $adminUser = User::firstOrNew(['email' => 'admin@example.com']);
        $generatedPassword = null;

        if (! $adminUser->exists) {
            $generatedPassword = $configuredPassword === '' ? Str::random(64) : null;
            $adminUser->name = 'Admin User';
            $adminUser->email_verified_at = now();
            $adminUser->password = Hash::make($generatedPassword ?? $configuredPassword);
        } elseif ($configuredPassword !== '' && ! Hash::check($configuredPassword, $adminUser->password)) {
            $adminUser->password = Hash::make($configuredPassword);
        }

        $team = Team::firstOrFail();
        $adminUser->forceFill(['current_team_id' => $team->id])->save();
        $adminUser->teams()->syncWithoutDetaching([$team->id]);

        setPermissionsTeamId($team->id);
        $role = Role::where('name', 'super_admin')->firstOrFail();
        $adminUser->assignRole($role);

        if (app()->environment('local') && $generatedPassword !== null) {
            $this->command?->info("Admin password: {$generatedPassword}");
        }
    }
}
