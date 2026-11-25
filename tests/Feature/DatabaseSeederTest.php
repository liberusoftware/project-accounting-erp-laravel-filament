<?php

use Database\Seeders\DatabaseSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Livewire\Livewire;

use function Pest\Laravel\seed;

it('seeds a default team and an admin user with the super_admin role', function () {
    seed(DatabaseSeeder::class);

    // Default team
    $team = Team::where('name', 'Default')->first();
    expect($team)->not->toBeNull();

    // Admin user on the team
    $admin = User::where('email', 'admin@example.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin->teams()->where('teams.id', $team->id)->exists())->toBeTrue();

    // super_admin role assigned in the team's permission context
    setPermissionsTeamId($team->id);
    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();
    expect($admin->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('signs into Filament with the generated password even after reseeding', function () {
    $this->app->instance('env', 'local');
    config(['app.seed_admin_password' => null]);

    expect(Artisan::call('db:seed', ['--class' => DatabaseSeeder::class]))->toBe(0);
    preg_match('/Admin password: (\S+)/', Artisan::output(), $password);
    expect($password)->toHaveCount(2);
    expect(Artisan::call('db:seed', ['--class' => DatabaseSeeder::class]))->toBe(0);
    expect(Artisan::output())->not->toContain('Admin password:');

    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    $panel = Filament::getPanel('admin');
    $dashboardUrl = $panel->getUrl($admin->currentTeam);
    setPermissionsTeamId(null);

    $this->get($panel->getLoginUrl())->assertOk();
    Livewire::test(Login::class)
        ->set('data.email', $admin->email)
        ->set('data.password', $password[1])
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect($dashboardUrl);

    $this->assertAuthenticatedAs($admin);
    $this->get($dashboardUrl)->assertOk();
});
