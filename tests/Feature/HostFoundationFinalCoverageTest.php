<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/NamespaceCoverageShims.php';

use Filament\Schemas\Schema;
use Illuminate\Auth\Events\Registered;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Organizations\Actions\InviteMember;
use Liberu\Foundation\Organizations\Actions\TransferOwnership;
use Liberu\Foundation\Organizations\Http\Middleware\TeamsPermission;
use Liberu\Foundation\Organizations\Listeners\AssignDefaultTeamRole;
use Liberu\Foundation\Organizations\Listeners\CreatePersonalTeam;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Support\NullTeamJobNotifier;
use Liberu\Foundation\Organizations\Support\PortalCustomer;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\Theme\Cache\ThemeCache;
use Liberu\Foundation\Theme\Discovery\ThemeDiscovery;
use Liberu\Foundation\Theme\Exceptions\InvalidTheme;
use Liberu\Foundation\Theme\Manifests\ThemeManifest;
use Liberu\Foundation\Theme\Providers\ThemeServiceProvider;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Liberu\Foundation\Theme\Tests\Fixtures\ComposerAvailability;
use Liberu\Foundation\Webhooks\Support\WebhookUrlPolicy;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

it('covers TeamRoleResource fallback form components when RolePermissionBuilder is unavailable', function (): void {
    $builderClass = 'Liberu\\Foundation\\RolesPermissionsFilament\\Forms\\RolePermissionBuilder';
    $GLOBALS['__coverage_shim_hide_classes'][$builderClass] = true;

    try {
        $schema = TeamRoleResource::form(Schema::make());

        expect($schema->getComponents())->toHaveCount(2);
    } finally {
        unset($GLOBALS['__coverage_shim_hide_classes'][$builderClass]);
    }
});

it('covers InviteMember rejection of disallowed roles', function (): void {
    $owner = User::factory()->create();
    $team = Team::create([
        'user_id' => $owner->id,
        'name' => 'Invite Reject Team',
        'personal_team' => false,
    ]);

    expect(fn () => (new InviteMember())->handle($team->id, 'blocked@example.com', 'super_admin', $owner->id))
        ->toThrow(InvalidArgumentException::class, 'The role cannot be assigned to a team member.');
});

it('covers TransferOwnership rejection when successor is not an active team member', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::create([
        'user_id' => $owner->id,
        'name' => 'Transfer Ownership Team',
        'personal_team' => false,
    ]);

    expect(fn () => (new TransferOwnership())->handle($team, $owner->id, $outsider->id, true))
        ->toThrow(RuntimeException::class, 'The successor must be an active member.');
});

it('covers every branch of TeamsPermission middleware', function (): void {
    if (! RouteFacade::has('login')) {
        RouteFacade::get('/test-login', fn () => 'login')->name('login');
    }
    if (! RouteFacade::has('home')) {
        RouteFacade::get('/test-home', fn () => 'home')->name('home');
    }

    $middleware = new TeamsPermission();
    $next = static fn (Request $request) => response('ok');

    // 1. Guest redirects to login
    Auth::logout();
    $guestResponse = $middleware->handle(Request::create('/app', 'GET'), $next);
    expect($guestResponse->isRedirect())->toBeTrue();

    // 2. Admin bypasses middleware
    $admin = User::factory()->create();
    $adminTeam = Team::create([
        'user_id' => $admin->id,
        'name' => 'Admin Team',
        'personal_team' => true,
    ]);
    $admin->forceFill(['current_team_id' => $adminTeam->id])->save();
    setPermissionsTeamId($adminTeam->id);
    $adminRole = SpatieRole::firstOrCreate([
        'name' => Role::Admin->value,
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $admin->assignRole($adminRole);
    Auth::login($admin);

    $adminResponse = $middleware->handle(Request::create('/app', 'GET'), $next);
    expect($adminResponse->getContent())->toBe('ok');

    // 3. Authenticated user without currentTeam passes through
    $noTeamUser = User::factory()->create(['current_team_id' => null]);
    Auth::login($noTeamUser);
    $noTeamResponse = $middleware->handle(Request::create('/app', 'GET'), $next);
    expect($noTeamResponse->getContent())->toBe('ok');

    // 4. Member with currentTeam
    $member = User::factory()->create();
    $team = Team::create([
        'user_id' => $admin->id,
        'name' => 'Member Team',
        'personal_team' => false,
    ]);
    $otherTeam = Team::create([
        'user_id' => $admin->id,
        'name' => 'Other Team',
        'personal_team' => false,
    ]);
    $team->users()->attach($member, ['role' => 'member']);
    $member->forceFill(['current_team_id' => $team->id])->save();
    $member->refresh();
    setPermissionsTeamId($team->id);
    Auth::login($member);

    $makeRequest = static function (?string $routeName, ?int $tenantId = null): Request {
        $request = Request::create('/app/resources', 'GET');
        if ($routeName === null) {
            return $request;
        }
        $route = new Route('GET', '/app/{tenant?}', static fn () => 'ok');
        if ($routeName !== '') {
            $route->name($routeName);
        }
        $route->bind($request);
        if ($tenantId !== null) {
            $route->setParameter('tenant', (string) $tenantId);
        }
        $request->setRouteResolver(static fn () => $route);

        return $request;
    };

    // 4a. Wrong tenant redirects home
    $wrongTenantResponse = $middleware->handle($makeRequest('filament.app.resources.invoices.index', $otherTeam->id), $next);
    expect($wrongTenantResponse->isRedirect())->toBeTrue();

    // 4b. Request with no route passes through
    expect($middleware->handle($makeRequest(null), $next)->getContent())->toBe('ok');

    // 4c. Route outside filament.app.resources.* passes through
    expect($middleware->handle($makeRequest('dashboard', $team->id), $next)->getContent())->toBe('ok');

    // 4d. Unknown action suffix passes through
    expect($middleware->handle($makeRequest('filament.app.resources.invoices.archive', $team->id), $next)->getContent())->toBe('ok');

    // 4e. Mapped action whose permission does not exist passes through
    expect($middleware->handle($makeRequest('filament.app.resources.nonexistent-things.index', $team->id), $next)->getContent())->toBe('ok');

    // 4f. Mapped action whose permission exists and user lacks it redirects home
    $permission = Permission::firstOrCreate([
        'name' => 'view:Invoice',
        'guard_name' => 'web',
    ]);
    $deniedResponse = $middleware->handle($makeRequest('filament.app.resources.invoices.index', $team->id), $next);
    expect($deniedResponse->isRedirect())->toBeTrue();

    // 4g. Grant permission and allow through
    $member->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $allowedResponse = $middleware->handle($makeRequest('filament.app.resources.invoices.index', $team->id), $next);
    expect($allowedResponse->getContent())->toBe('ok');
});

it('covers AssignDefaultTeamRole, CreatePersonalTeam, and NullTeamJobNotifier', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::create([
        'user_id' => $owner->id,
        'name' => 'Listener Team',
        'personal_team' => false,
    ]);
    $team->users()->attach($member, ['role' => 'member']);

    app(AssignDefaultTeamRole::class)->handle(new TeamMemberAdded($team, $member));
    setPermissionsTeamId($team->id);
    expect($member->fresh()->roles()->count())->toBeGreaterThan(0);

    $registeredUser = User::factory()->create(['current_team_id' => null]);
    app(CreatePersonalTeam::class)->handle(new Registered($registeredUser));
    expect($registeredUser->fresh()->current_team_id)->not->toBeNull();

    (new NullTeamJobNotifier())->notify($owner, 'Export ready', 'Your export completed.', true);
    expect(true)->toBeTrue();
});

it('covers PortalCustomer forEmail and existsForEmail across all branches', function (): void {
    expect(PortalCustomer::forEmail(null))->toBeNull();
    expect(PortalCustomer::forEmail('   '))->toBeNull();
    expect(PortalCustomer::forEmail('missing-portal-user@example.com'))->toBeNull();

    $plainUser = User::factory()->create(['email' => 'plain-user@example.com']);
    expect(PortalCustomer::existsForEmail($plainUser->email))->toBeFalse();

    $customer = User::factory()->create(['email' => 'portal-customer@example.com']);
    $customerRole = SpatieRole::firstOrCreate([
        'name' => Role::Customer->value,
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    $tables = config('permission.table_names');
    $morphKey = config('permission.column_names.model_morph_key');
    $teamKey = config('permission.column_names.team_foreign_key');
    $roleKey = app(PermissionRegistrar::class)->pivotRole;

    DB::table($tables['model_has_roles'])->insert([
        $roleKey => $customerRole->getKey(),
        'model_type' => $customer->getMorphClass(),
        $morphKey => $customer->getKey(),
        $teamKey => null,
    ]);

    expect(PortalCustomer::existsForEmail($customer->email))->toBeTrue();
    expect(PortalCustomer::forEmail($customer->email)?->getKey())->toBe($customer->getKey());
});

it('covers ThemeCache nested unexpected object and write/clear failure paths', function (): void {
    $tempFile = sys_get_temp_dir().'/liberu-theme-cache-nested-'.uniqid('', true).'.cache';
    file_put_contents($tempFile, serialize(['nested' => [new stdClass()]]));

    try {
        expect(fn () => (new ThemeCache())->load($tempFile))
            ->toThrow(InvalidTheme::class, 'Theme registry cache is invalid.');
    } finally {
        @unlink($tempFile);
    }

    $failingWriteFs = new class() extends Filesystem
    {
        public function put($path, $contents, $lock = false): int|false
        {
            return false;
        }
    };

    expect(fn () => (new ThemeCache($failingWriteFs))->write([], '/tmp/unreachable-theme-cache'))
        ->toThrow(InvalidTheme::class, 'Unable to write theme cache.');

    $failingDeleteFs = new class() extends Filesystem
    {
        public function isFile($file): bool
        {
            return true;
        }

        public function delete($paths): bool
        {
            return false;
        }
    };

    expect(fn () => (new ThemeCache($failingDeleteFs))->clear('/tmp/unreachable-theme-cache'))
        ->toThrow(InvalidTheme::class, 'Unable to clear theme cache.');
});

it('covers ThemeDiscovery fallback when Composer InstalledVersions is unavailable', function (): void {
    ComposerAvailability::$available = false;

    try {
        $themes = (new ThemeDiscovery())->discover(base_path('themes'));

        expect($themes)->not->toBeEmpty();
    } finally {
        ComposerAvailability::$available = true;
    }
});

it('covers ThemeManifest validation errors and getters', function (): void {
    $dir = sys_get_temp_dir().'/liberu-theme-manifest-'.uniqid('', true);
    mkdir($dir.'/resources/css', 0755, true);
    file_put_contents($dir.'/resources/css/app.css', '/* ok */');

    $validPayload = [
        'name' => 'demo-theme',
        'display_name' => 'Demo Theme',
        'version' => '1.2.3',
        'provider' => ThemeServiceProvider::class,
        'type' => 'public',
        'parent' => null,
        'optimized_for' => ['accounting'],
        'tested_with' => ['laravel'],
        'required_capabilities' => [],
        'optional_capabilities' => ['dark-mode'],
        'supports' => [],
        'assets' => [
            'css' => ['resources/css/app.css'],
            'js' => [],
        ],
    ];

    try {
        // Missing key (line 16)
        $missingPath = $dir.'/missing.json';
        $missingPayload = $validPayload;
        unset($missingPayload['display_name']);
        file_put_contents($missingPath, json_encode($missingPayload, JSON_THROW_ON_ERROR));
        expect(fn () => ThemeManifest::fromFile($missingPath))
            ->toThrow(InvalidTheme::class, 'is missing [display_name]');

        // Invalid name (line 19)
        $badNamePath = $dir.'/bad-name.json';
        file_put_contents($badNamePath, json_encode(['name' => 'Invalid_Name!'] + $validPayload, JSON_THROW_ON_ERROR));
        expect(fn () => ThemeManifest::fromFile($badNamePath))
            ->toThrow(InvalidTheme::class, 'Theme name is invalid.');

        // Invalid type (line 21)
        $badTypePath = $dir.'/bad-type.json';
        file_put_contents($badTypePath, json_encode(['type' => 'invalid'] + $validPayload, JSON_THROW_ON_ERROR));
        expect(fn () => ThemeManifest::fromFile($badTypePath))
            ->toThrow(InvalidTheme::class, 'has an invalid type.');

        // Invalid asset (line 25)
        $badAssetPath = $dir.'/bad-asset.json';
        file_put_contents($badAssetPath, json_encode(['assets' => ['css' => ['../escape.css'], 'js' => []]] + $validPayload, JSON_THROW_ON_ERROR));
        expect(fn () => ThemeManifest::fromFile($badAssetPath))
            ->toThrow(InvalidTheme::class, 'references an invalid css asset.');

        // Valid manifest getters (lines 45, 55, 75)
        $validPath = $dir.'/theme.json';
        file_put_contents($validPath, json_encode($validPayload, JSON_THROW_ON_ERROR));
        $manifest = ThemeManifest::fromFile($validPath);

        expect($manifest->version())->toBe('1.2.3')
            ->and($manifest->type())->toBe('public')
            ->and($manifest->optionalCapabilities())->toBe(['dark-mode']);
    } finally {
        @unlink($dir.'/missing.json');
        @unlink($dir.'/bad-name.json');
        @unlink($dir.'/bad-type.json');
        @unlink($dir.'/bad-asset.json');
        @unlink($dir.'/theme.json');
        @unlink($dir.'/resources/css/app.css');
        @rmdir($dir.'/resources/css');
        @rmdir($dir.'/resources');
        @rmdir($dir);
    }
});

it('covers ThemeServiceProvider selected theme resolution and theme_helpers', function (): void {
    Auth::logout();
    session()->forget('theme_preference');

    $manager = app(ThemeManager::class);
    $selected = $manager->selectForSurface('public');

    $provider = app()->getProvider(ThemeServiceProvider::class);
    expect($provider)->toBeInstanceOf(ThemeServiceProvider::class);

    $determined = Closure::bind(
        fn (): string => $this->determineActiveTheme(),
        $provider,
        ThemeServiceProvider::class,
    )();

    expect($determined)->toBe($selected);

    // Re-require theme_helpers.php so function_exists guards are recorded, and invoke helpers
    require base_path('modules/theme-support/src/Support/theme_helpers.php');

    expect(theme())->toBeInstanceOf(ThemeManager::class)
        ->and(active_theme())->toBeString()
        ->and(theme_asset('app.css'))->toContain('app.css')
        ->and(theme_path())->toBeString()
        ->and(theme_views_path())->toBeString()
        ->and(theme_layout('app'))->toBe('layouts.app');

    set_theme(active_theme());
});

it('covers WebhookUrlPolicy when cURL extension is unavailable', function (): void {
    $GLOBALS['__coverage_shim_hide_extensions']['curl'] = true;

    try {
        expect(fn () => (new WebhookUrlPolicy())->options('https://93.184.216.34/webhook'))
            ->toThrow(RuntimeException::class, 'Secure webhook delivery requires the cURL extension.');
    } finally {
        unset($GLOBALS['__coverage_shim_hide_extensions']['curl']);
    }
});
