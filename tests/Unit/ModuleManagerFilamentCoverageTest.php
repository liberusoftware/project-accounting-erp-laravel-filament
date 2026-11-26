<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleManagement as Modules;
use Liberu\Foundation\ModuleManager\ModuleValidator;
use Liberu\Foundation\ModuleManagerFilament\ModuleManagerFilamentPlugin;
use Liberu\Foundation\ModuleManagerFilament\ModuleManagerFilamentServiceProvider;
use Liberu\Foundation\ModuleManagerFilament\Pages\ModuleManagement;
use Livewire\Livewire;

uses(RefreshDatabase::class);

final class ModuleManagerFilamentCovProvider extends ServiceProvider {}

beforeEach(function (): void {
    $this->mmfRoot = sys_get_temp_dir().'/mmf-cov-'.bin2hex(random_bytes(6));
    mkdir($this->mmfRoot, 0777, true);
    $this->mmfOriginalComposer = require base_path('vendor/composer/installed.php');
    $composer = $this->mmfOriginalComposer;

    $manifests = [];
    foreach (['alpha' => false, 'beta' => true, 'broken' => true, 'locked' => true] as $name => $enabled) {
        $dir = "{$this->mmfRoot}/{$name}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/composer.json", json_encode([
            'name' => "fixture-mmf/{$name}",
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => $name]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents("{$dir}/module.json", json_encode([
            'name' => $name,
            'display_name' => ucfirst($name),
            'description' => $name === 'beta' ? '' : "Fixture {$name}",
            'version' => '1.0.0',
            'category' => 'capability',
            'provider' => ModuleManagerFilamentCovProvider::class,
            'requires' => ['packages' => []],
            'capabilities' => [],
            'features' => ['Fixture'],
            'default_enabled' => $enabled,
            'module_type' => 'feature',
        ], JSON_THROW_ON_ERROR));

        $manifests[$name] = Manifest::fromFile("{$dir}/module.json");
        $composer['versions']["fixture-mmf/{$name}"] = [
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'reference' => null,
            'type' => 'liberu-module',
            'install_path' => $dir,
            'aliases' => [],
            'dev_requirement' => false,
        ];
    }

    InstalledVersions::reload($composer);

    if (! Schema::hasTable('module_activations')) {
        Schema::create('module_activations', function ($table): void {
            $table->string('module')->primary();
            $table->boolean('enabled');
            $table->timestamps();
        });
    }

    $this->mmfInventory = new ModuleInventory($manifests, [], ['broken' => ['Fixture failure']], ['locked']);
    $packages = new PackageManifest(new Filesystem(), base_path(), "{$this->mmfRoot}/packages.php");
    $packages->manifest = [];
    $this->app->instance(
        Modules::class,
        new Modules($this->mmfInventory, app(ModuleActivations::class), new ModuleValidator(), $packages, [], []),
    );

    $panel = Panel::make()->id('mmf-cov-test')->path('mmf-cov-test')->plugin(ModuleManagerFilamentPlugin::make());
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    InstalledVersions::reload($this->mmfOriginalComposer);
    (new Filesystem())->deleteDirectory($this->mmfRoot);
});

it('boots service provider, configures plugin, and enforces gate access', function (): void {
    $provider = $this->app->register(ModuleManagerFilamentServiceProvider::class, true);
    $provider->boot();

    $plugin = ModuleManagerFilamentPlugin::make();
    $panel = Panel::make()->id('mmf-plugin-unit');
    $plugin->register($panel);
    $plugin->boot($panel);

    expect($plugin->getId())->toBe('liberu-module-manager')
        ->and($panel->getPages())->toContain(ModuleManagement::class)
        ->and(ModuleManagement::getSlug())->toBe('modules')
        ->and(ModuleManagement::canAccess())->toBeFalse();

    $this->actingAs((new User())->forceFill(['id' => 1, 'ops' => true]));
    Gate::define(ModuleManagement::ABILITY, fn (User $user): bool => $user->getAttribute('ops') === true);
    expect(ModuleManagement::canAccess())->toBeTrue();

    $this->actingAs((new User())->forceFill(['id' => 2, 'ops' => false]));
    expect(ModuleManagement::canAccess())->toBeFalse();
});

it('renders module table states, executes enable and disable actions, handles refusals, and blocks revoked access', function (): void {
    Gate::define(ModuleManagement::ABILITY, fn (User $user): bool => $user->getAttribute('ops') === true);
    $this->actingAs((new User())->forceFill(['id' => 1, 'ops' => true]));

    Livewire::test(ModuleManagement::class)
        ->assertSuccessful()
        ->assertSee('Fixture failure')
        ->assertSee('This application requires this module.')
        ->assertTableActionVisible('enable', 'alpha')
        ->assertTableActionHidden('disable', 'alpha')
        ->assertTableActionVisible('disable', 'beta')
        ->assertTableActionHidden('enable', 'beta')
        ->assertTableActionVisible('disable', 'broken')
        ->assertTableActionHidden('enable', 'locked')
        ->assertTableActionHidden('disable', 'locked')
        ->callTableAction('enable', 'alpha')
        ->assertTableActionVisible('disable', 'alpha')
        ->assertTableActionHidden('enable', 'alpha')
        ->callTableAction('disable', 'beta')
        ->assertTableActionVisible('enable', 'beta')
        ->assertTableActionHidden('disable', 'beta');

    expect(app(ModuleActivations::class)->all())
        ->toHaveKey('alpha', true)
        ->toHaveKey('beta', false);

    // Refusal path when module fails validation before activation
    $alphaManifestPath = "{$this->mmfRoot}/alpha/module.json";
    $data = json_decode((string) file_get_contents($alphaManifestPath), true, flags: JSON_THROW_ON_ERROR);
    $data['provider'] = 'Missing\\MmfProvider';
    file_put_contents($alphaManifestPath, json_encode($data, JSON_THROW_ON_ERROR));

    $manifests = $this->mmfInventory->manifests;
    $manifests['alpha'] = Manifest::fromFile($alphaManifestPath);
    $refusalInventory = new ModuleInventory($manifests, [], ['alpha' => ['Fixture invalid metadata']], ['locked']);
    $packages = new PackageManifest(new Filesystem(), base_path(), "{$this->mmfRoot}/packages.php");
    $packages->manifest = [];
    app(ModuleActivations::class)->set('alpha', false);
    $this->app->instance(
        Modules::class,
        new Modules($refusalInventory, app(ModuleActivations::class), new ModuleValidator(), $packages, [], []),
    );

    Livewire::test(ModuleManagement::class)->callTableAction('enable', 'alpha');
    Notification::assertNotified('Not changed');

    // Revoking gate mid-session aborts 403 on mounted action
    $page = Livewire::test(ModuleManagement::class);
    Gate::define(ModuleManagement::ABILITY, fn (): bool => false);
    $page->call('mountAction', 'enable', [], ['table' => true, 'recordKey' => 'alpha'])->assertForbidden();
});
