<?php

declare(strict_types=1);

use App\Http\Kernel;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\Filament\CustomerPanelProvider;
use App\Providers\Filament\VendorPanelProvider;
use App\Providers\RouteServiceProvider;
use Filament\Panel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Horizon\Horizon;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\ModuleManagerFilament\Pages\ModuleManagement;
use Spatie\Permission\Models\Role;

it('covers AppServiceProvider gates and Horizon auth callback', function (): void {
    (new AppServiceProvider($this->app))->boot();

    $adminUser = new class() extends User
    {
        public function isAdmin(): bool
        {
            return true;
        }
    };
    $regularUser = new class() extends User
    {
        public function isAdmin(): bool
        {
            return false;
        }
    };

    expect(Gate::forUser($adminUser)->allows(ModuleManagement::ABILITY))->toBeTrue()
        ->and(Gate::forUser($regularUser)->allows(ModuleManagement::ABILITY))->toBeFalse();

    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $normalUser = User::factory()->create();

    expect(Gate::forUser($superAdmin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($normalUser)->allows('viewHorizon'))->toBeFalse();

    $superRequest = Request::create('/horizon', 'GET');
    $superRequest->setUserResolver(fn () => $superAdmin);
    $normalRequest = Request::create('/horizon', 'GET');
    $normalRequest->setUserResolver(fn () => $normalUser);

    $this->actingAs($superAdmin);
    expect(Horizon::check($superRequest))->toBeTrue();
    $this->actingAs($normalUser);
    expect(Horizon::check($normalRequest))->toBeFalse();
});

it('covers AuthServiceProvider, RouteServiceProvider, Kernel, and Customer/Vendor PanelProviders', function (): void {
    (new AuthServiceProvider($this->app))->boot();

    $routeProvider = new RouteServiceProvider($this->app);
    $routeProvider->boot();

    $limiter = RateLimiter::limiter('api');
    expect($limiter)->toBeCallable();

    $user = User::factory()->create();
    $authedRequest = Request::create('/api/test', 'GET');
    $authedRequest->setUserResolver(fn () => $user);
    $guestRequest = Request::create('/api/test', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);

    expect($limiter($authedRequest)->key)->toBe($user->id)
        ->and($limiter($guestRequest)->key)->toBe('127.0.0.1');

    $kernel = app(Kernel::class);
    expect($kernel->getMiddlewareGroups())->toHaveKeys(['web', 'api']);

    $customerPanel = (new CustomerPanelProvider($this->app))->panel(Panel::make());
    $vendorPanel = (new VendorPanelProvider($this->app))->panel(Panel::make());

    expect($customerPanel->getId())->toBe('customer')
        ->and($customerPanel->getPath())->toBe('portal')
        ->and($vendorPanel->getId())->toBe('vendor')
        ->and($vendorPanel->getPath())->toBe('vendor-portal');
});
