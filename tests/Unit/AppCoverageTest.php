<?php

declare(strict_types=1);

use App\Exceptions\Handler;
use App\Filament\ModulePlugins;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\CustomerPanelProvider;
use App\Providers\Filament\VendorPanelProvider;
use App\Providers\RouteServiceProvider;
use App\Support\ThemeColors;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Laravel\Horizon\Horizon;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManagerFilament\Pages\ModuleManagement;

it('covers app providers, handler, theme colors, and panel providers', function (): void {
    // Handler::register() and reportable callback
    $handler = app(Handler::class);
    $handler->register();
    $handler->report(new RuntimeException('test reportable'));

    // AuthServiceProvider & BroadcastServiceProvider
    (new AuthServiceProvider($this->app))->boot();
    (new BroadcastServiceProvider($this->app))->boot();

    // RouteServiceProvider rate limiter callback
    (new RouteServiceProvider($this->app))->boot();
    $apiLimiter = RateLimiter::limiter('api');
    expect($apiLimiter)->toBeCallable();
    $limit = $apiLimiter(Request::create('/api/ping', 'GET'));
    expect($limit)->not->toBeNull();

    // AppServiceProvider gates, Horizon auth, Password defaults, and production URL scheme
    $user = new class extends User
    {
        public bool $adminFlag = true;

        public function isAdmin(): bool
        {
            return $this->adminFlag;
        }

        public function hasRole($roles, ?string $guard = null): bool
        {
            return $this->adminFlag;
        }
    };

    $this->actingAs($user);
    expect(Gate::forUser($user)->allows(ModuleManagement::ABILITY))->toBeTrue();
    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();

    $horizonRequest = Request::create('/horizon', 'GET');
    $horizonRequest->setUserResolver(static fn () => $user);
    expect(Horizon::check($horizonRequest))->toBeTrue();

    // Production branch of AppServiceProvider::configureUrl()
    $originalEnv = $this->app['env'];
    $this->app['env'] = 'production';
    try {
        (new AppServiceProvider($this->app))->boot();
        expect(Password::default())->toBeInstanceOf(Password::class);
    } finally {
        $this->app['env'] = $originalEnv;
        URL::forceScheme(null);
    }

    // ThemeColors and PanelProviders
    expect(app(ThemeColors::class)->forSite())->toHaveKey('primary');
    expect((new AdminPanelProvider($this->app))->panel(Panel::make()))->toBeInstanceOf(Panel::class);
    expect((new AppPanelProvider($this->app))->panel(Panel::make()))->toBeInstanceOf(Panel::class);
    expect((new CustomerPanelProvider($this->app))->panel(Panel::make()))->toBeInstanceOf(Panel::class);
    expect((new VendorPanelProvider($this->app))->panel(Panel::make()))->toBeInstanceOf(Panel::class);
});

it('covers ModulePlugins resolution, container fallback, invalid plugin, and duplicate id branches', function (): void {
    $validPlugin = new class implements Plugin
    {
        public function getId(): string
        {
            return 'test-valid-plugin';
        }

        public function register(Panel $panel): void {}

        public function boot(Panel $panel): void {}
    };

    $invalidPlugin = new stdClass;

    $this->app->instance($validPlugin::class, $validPlugin);
    $this->app->instance($invalidPlugin::class, $invalidPlugin);

    $makeManifest = static function (string $name, array $plugins): Manifest {
        $tmp = tempnam(sys_get_temp_dir(), 'mod_manifest_');
        file_put_contents($tmp, json_encode([
            'name' => $name,
            'display_name' => $name,
            'description' => 'Test module',
            'version' => '1.0.0',
            'category' => 'presentation',
            'provider' => AppServiceProvider::class,
            'requires' => [],
            'capabilities' => [],
            'features' => ['Test feature'],
            'default_enabled' => true,
            'presentation' => [
                'filament' => ['test-panel' => $plugins],
            ],
        ], JSON_THROW_ON_ERROR));
        $manifest = Manifest::fromFile($tmp);
        @unlink($tmp);

        return $manifest;
    };

    config()->set('modules.enabled', []);
    config()->set('modules.disabled', []);

    // Valid plugin resolved via app() (no static make() method)
    $registryA = new ModuleRegistry([$makeManifest('test-mod-a', [$validPlugin::class])]);
    $plugins = (new ModulePlugins($registryA))->forPanel('test-panel');
    expect($plugins)->toHaveCount(1)
        ->and($plugins[0]->getId())->toBe('test-valid-plugin');

    // Invalid plugin throws RuntimeException
    $registryInvalid = new ModuleRegistry([$makeManifest('test-mod-invalid', [$invalidPlugin::class])]);
    expect(fn () => (new ModulePlugins($registryInvalid))->forPanel('test-panel'))
        ->toThrow(RuntimeException::class, 'invalid Filament plugin');

    // Duplicate plugin id throws RuntimeException
    $registryDup = new ModuleRegistry([$makeManifest('test-mod-dup', [$validPlugin::class, $validPlugin::class])]);
    expect(fn () => (new ModulePlugins($registryDup))->forPanel('test-panel'))
        ->toThrow(RuntimeException::class, 'Duplicate Filament plugin id');
});
