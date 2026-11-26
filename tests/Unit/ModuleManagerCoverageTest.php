<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\DependencyResolutionFailed;
use Liberu\Foundation\ModuleManager\Exceptions\InvalidManifest;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\Exceptions\RequiredModuleFailed;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleDiscovery;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;
use Liberu\Foundation\ModuleManager\ModuleManagement;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidationGuard;
use Liberu\Foundation\ModuleManager\ModuleValidator;

uses(RefreshDatabase::class);

final class ModuleManagerCovGoodProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('mm_cov.good_booted', true);
    }
}

final class ModuleManagerCovThrowingProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new RuntimeException('cov provider exploded');
    }
}

final class ModuleManagerCovDummyPlugin {}

function mmReloadInstalled(array $data, ?bool $canGetVendors = false): void
{
    InstalledVersions::reload($data);
    $prop = new ReflectionProperty(InstalledVersions::class, 'canGetVendors');
    $prop->setValue(null, $canGetVendors);
}

beforeEach(function (): void {
    $this->mmRoot = sys_get_temp_dir().'/mm-cov-'.bin2hex(random_bytes(6));
    mkdir($this->mmRoot, 0777, true);
    $this->mmOriginalInstalled = require base_path('vendor/composer/installed.php');
    $this->mmInstalled = $this->mmOriginalInstalled;
    $this->mmInstalled['versions'] = array_filter(
        $this->mmInstalled['versions'] ?? [],
        static fn (array $info): bool => ($info['type'] ?? null) !== 'liberu-module',
    );
    mmReloadInstalled($this->mmInstalled, false);
});

afterEach(function (): void {
    mmReloadInstalled($this->mmOriginalInstalled, null);
    (new Filesystem())->deleteDirectory($this->mmRoot);
});

function mmWriteFixture(
    object $test,
    string $dirName,
    array $manifestOverrides = [],
    ?array $composerOverrides = [],
    ?string $rawManifest = null,
    bool $installed = true,
    string $installedVersion = '1.0.0',
    ?string $installedPackageOverride = null,
    bool $writeComposer = true,
): string {
    $dir = "{$test->mmRoot}/{$dirName}";
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $name = $manifestOverrides['name'] ?? $dirName;
    $manifest = array_replace_recursive([
        'name' => $name,
        'display_name' => ucfirst($name),
        'description' => "Description for {$name}",
        'version' => '1.0.0',
        'category' => 'capability',
        'provider' => ModuleManagerCovGoodProvider::class,
        'requires' => ['packages' => []],
        'capabilities' => [],
        'features' => ["Feature {$name}"],
        'default_enabled' => true,
    ], $manifestOverrides);

    file_put_contents("{$dir}/module.json", $rawManifest ?? json_encode($manifest, JSON_THROW_ON_ERROR));

    if ($writeComposer) {
        $composer = array_merge([
            'name' => "local/{$name}",
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => $name]],
        ], $composerOverrides ?? []);
        file_put_contents("{$dir}/composer.json", json_encode($composer, JSON_THROW_ON_ERROR));

        $pkgName = $installedPackageOverride ?? ($composer['name'] ?? null);
        if ($installed && is_string($pkgName)) {
            $test->mmInstalled['versions'][$pkgName] = [
                'pretty_version' => $installedVersion,
                'version' => ltrim($installedVersion, 'v').'.0',
                'reference' => null,
                'type' => 'liberu-module',
                'install_path' => $dir,
                'aliases' => [],
                'dev_requirement' => false,
            ];
            mmReloadInstalled($test->mmInstalled);
        }
    }

    return $dir;
}

it('validates Manifest parsing, errors, getters, and fallbacks', function (): void {
    $validDir = mmWriteFixture($this, 'full-mod', [
        'module_type' => 'feature',
        'capabilities' => ['cap.one'],
        'features' => ['First feature', 'Second feature'],
        'requires' => [
            'php' => '^8.2',
            'laravel' => '^12.0 || ^13.0',
            'packages' => ['local/dep' => '^1.0'],
            'capabilities' => ['other.cap' => '^1.0'],
        ],
        'presentation' => ['filament' => ['admin' => [ModuleManagerCovDummyPlugin::class, 123]]],
    ]);

    $manifest = Manifest::fromFile("{$validDir}/module.json");
    expect($manifest->name())->toBe('full-mod')
        ->and($manifest->version())->toBe('1.0.0')
        ->and($manifest->displayName())->toBe('Full-mod')
        ->and($manifest->description())->toBe('Description for full-mod')
        ->and($manifest->category())->toBe('capability')
        ->and($manifest->provider())->toBe(ModuleManagerCovGoodProvider::class)
        ->and($manifest->isFeature())->toBeTrue()
        ->and($manifest->defaultEnabled())->toBeTrue()
        ->and($manifest->capabilities())->toBe(['cap.one'])
        ->and($manifest->features())->toBe(['First feature', 'Second feature'])
        ->and($manifest->requiredPackages())->toBe(['local/dep' => '^1.0'])
        ->and($manifest->requiredCapabilities())->toBe(['other.cap' => '^1.0'])
        ->and($manifest->phpConstraint())->toBe('^8.2')
        ->and($manifest->laravelConstraint())->toBe('^12.0 || ^13.0')
        ->and($manifest->filamentPlugins('admin'))->toBe([ModuleManagerCovDummyPlugin::class])
        ->and($manifest->filamentPlugins('app'))->toBe([])
        ->and($manifest->toArray()['path'])->toBe($validDir);

    // Non-array nested fields safely return empty arrays
    $edgeDir = mmWriteFixture($this, 'edge-mod', [
        'requires' => ['packages' => 'bad', 'capabilities' => 'bad'],
        'presentation' => ['filament' => ['admin' => 'bad']],
    ]);
    $edge = Manifest::fromFile("{$edgeDir}/module.json");
    expect($edge->isFeature())->toBeFalse()
        ->and($edge->requiredPackages())->toBe([])
        ->and($edge->requiredCapabilities())->toBe([])
        ->and($edge->filamentPlugins('admin'))->toBe([]);

    // Invalid manifest cases
    $base = [
        'name' => 'valid-name',
        'display_name' => 'Valid',
        'description' => 'Desc',
        'version' => '1.0.0',
        'category' => 'capability',
        'provider' => ModuleManagerCovGoodProvider::class,
        'requires' => [],
        'capabilities' => [],
        'features' => ['One'],
        'default_enabled' => true,
    ];

    $invalidCases = [
        'missing key' => array_diff_key($base, ['version' => true]),
        'invalid name' => [...$base, 'name' => 'Invalid_Name'],
        'invalid requires' => [...$base, 'requires' => 'not-array'],
        'invalid category' => [...$base, 'category' => 'bogus'],
        'invalid module_type' => [...$base, 'module_type' => 'bogus'],
        'invalid default_enabled' => [...$base, 'default_enabled' => 'yes'],
        'invalid capability' => [...$base, 'capabilities' => ['BAD CAP']],
        'empty features' => [...$base, 'features' => []],
        'invalid feature' => [...$base, 'features' => [' leading space']],
        'duplicate features' => [...$base, 'features' => ['Same', 'same']],
    ];

    foreach ($invalidCases as $label => $payload) {
        $path = "{$this->mmRoot}/invalid-".md5($label).'.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        expect(fn () => Manifest::fromFile($path))->toThrow(InvalidManifest::class);
    }

    expect(RequiredModuleFailed::because('core', 'broken')->getMessage())
        ->toContain('Required module [core] cannot load: broken');
});

it('persists and applies ModuleActivations and handles database errors gracefully', function (): void {
    $activations = app(ModuleActivations::class);
    expect($activations->all())->toBe([]);

    $activations->set('alpha', true);
    $activations->set('beta', false);
    $activations->set('alpha', false);
    expect($activations->all())->toBe(['alpha' => false, 'beta' => false]);

    [$enabled, $disabled] = ModuleActivations::apply(['a'], ['b'], ['c' => true, 'd' => false]);
    expect($enabled)->toBe(['a', 'c'])
        ->and($disabled)->toBe(['b', 'd']);

    // Faulty connection returns empty array instead of throwing during boot
    $brokenResolver = new class() implements ConnectionResolverInterface
    {
        public function connection($name = null): never
        {
            throw new RuntimeException('db unavailable');
        }

        public function getDefaultConnection(): string
        {
            return 'sqlite';
        }

        public function setDefaultConnection($name): void {}
    };
    expect((new ModuleActivations($brokenResolver))->all())->toBe([]);
});

it('covers ModuleDiscovery strict and tolerant modes, duplicates, legacy dirs, and missing composer metadata', function (): void {
    // Missing composer.json
    mmWriteFixture($this, 'no-composer', writeComposer: false);
    expect(fn () => (new ModuleDiscovery())->discover([$this->mmRoot]))
        ->toThrow(InvalidManifest::class, 'has no composer.json');

    // Missing composer name
    $sub1 = "{$this->mmRoot}/sub1";
    mkdir($sub1, 0777, true);
    mmWriteFixture($this, 'sub1/no-name', ['name' => 'no-name'], ['name' => null], installed: false);
    expect(fn () => (new ModuleDiscovery())->discover([$sub1]))
        ->toThrow(InvalidManifest::class, 'missing or duplicate Composer package name');

    // Legacy module-liberu-* skipped when canonical sibling exists
    $sub2 = "{$this->mmRoot}/sub2";
    mkdir($sub2, 0777, true);
    mmWriteFixture($this, 'sub2/module-liberu-demo', ['name' => 'liberu-demo'], ['name' => 'liberusoftware/module-liberu-demo'], installed: false);
    mmWriteFixture($this, 'sub2/liberu-demo', ['name' => 'liberu-demo'], ['name' => 'liberusoftware/liberu-demo'], installed: false);
    $reg2 = (new ModuleDiscovery())->discover([$sub2]);
    expect($reg2->get('liberu-demo')?->path)->toBe(realpath("{$sub2}/liberu-demo"));

    // Duplicate Composer package name for different module -> throws
    $sub3 = "{$this->mmRoot}/sub3";
    mkdir($sub3, 0777, true);
    mmWriteFixture($this, 'sub3/mod-a', ['name' => 'mod-a'], ['name' => 'local/shared-pkg'], installed: false);
    mmWriteFixture($this, 'sub3/mod-b', ['name' => 'mod-b'], ['name' => 'local/shared-pkg'], installed: false);
    expect(fn () => (new ModuleDiscovery())->discover([$sub3]))
        ->toThrow(InvalidManifest::class, 'missing or duplicate Composer package name');

    // Identical copy of same package/name/version across two roots -> deduplicated
    $sub4a = "{$this->mmRoot}/sub4a";
    $sub4b = "{$this->mmRoot}/sub4b";
    mkdir($sub4a, 0777, true);
    mkdir($sub4b, 0777, true);
    mmWriteFixture($this, 'sub4a/same', ['name' => 'same'], ['name' => 'local/same'], installed: false);
    mmWriteFixture($this, 'sub4b/same', ['name' => 'same'], ['name' => 'local/same'], installed: false);
    expect((new ModuleDiscovery())->discover([$sub4a, $sub4b])->has('same'))->toBeTrue();

    // Duplicate module name with different package name -> throws
    $sub5 = "{$this->mmRoot}/sub5";
    mkdir($sub5, 0777, true);
    mmWriteFixture($this, 'sub5/dir-1', ['name' => 'dup-name'], ['name' => 'local/pkg-1'], installed: false);
    mmWriteFixture($this, 'sub5/dir-2', ['name' => 'dup-name'], ['name' => 'local/pkg-2'], installed: false);
    expect(fn () => (new ModuleDiscovery())->discover([$sub5]))
        ->toThrow(InvalidManifest::class, 'Duplicate module [dup-name]');

    // Duplicate capability -> throws in strict, recorded in tolerant
    $sub6 = "{$this->mmRoot}/sub6";
    mkdir($sub6, 0777, true);
    mmWriteFixture($this, 'sub6/cap-a', ['name' => 'cap-a', 'capabilities' => ['shared.cap']], ['name' => 'local/cap-a'], installed: false);
    mmWriteFixture($this, 'sub6/cap-b', ['name' => 'cap-b', 'capabilities' => ['shared.cap']], ['name' => 'local/cap-b'], installed: false);
    expect(fn () => (new ModuleDiscovery())->discover([$sub6]))
        ->toThrow(InvalidManifest::class, 'Duplicate capability [shared.cap]');

    [$tolerantReg, $problems] = (new ModuleDiscovery())->discoverTolerant([$sub6]);
    expect($tolerantReg->has('cap-a'))->toBeTrue()
        ->and($problems)->toHaveKey('cap-b');
});

it('covers ModuleRegistry serialization, feature queries, and all dependency resolution paths', function (): void {
    $dBase = mmWriteFixture($this, 'reg-base', ['name' => 'reg-base', 'capabilities' => ['reg.base'], 'features' => ['Base Feature']]);
    $dMid = mmWriteFixture($this, 'reg-mid', [
        'name' => 'reg-mid',
        'capabilities' => ['reg.mid'],
        'features' => ['Mid Feature'],
        'requires' => ['packages' => ['local/reg-base' => '^1.0', 'psr/log' => '^1.0 || ^2.0 || ^3.0']],
    ]);
    $dTop = mmWriteFixture($this, 'reg-top', [
        'name' => 'reg-top',
        'features' => ['Top Feature'],
        'requires' => ['packages' => ['local/reg-base' => '^1.0'], 'capabilities' => ['reg.mid' => '^1.0']],
    ]);
    $dNoComposer = mmWriteFixture($this, 'reg-nocomp', ['name' => 'reg-nocomp', 'default_enabled' => false], writeComposer: false);

    $manifests = [
        'reg-base' => Manifest::fromFile("{$dBase}/module.json"),
        'reg-mid' => Manifest::fromFile("{$dMid}/module.json"),
        'reg-top' => Manifest::fromFile("{$dTop}/module.json"),
        'reg-nocomp' => Manifest::fromFile("{$dNoComposer}/module.json"),
    ];
    $registry = new ModuleRegistry($manifests);

    // Serialization round-trip + invalid cache payload
    $unserialized = unserialize(serialize($registry));
    expect($unserialized)->toBeInstanceOf(ModuleRegistry::class)
        ->and($unserialized->has('reg-base'))->toBeTrue()
        ->and($unserialized->get('reg-base'))->not->toBeNull()
        ->and($unserialized->get('missing'))->toBeNull()
        ->and($unserialized->enabled('reg-top'))->toBeTrue()
        ->and($unserialized->enabled('reg-nocomp'))->toBeFalse();

    expect(function () use ($registry): void {
        $registry->__unserialize(['modules' => ['bad' => 'not-a-manifest']]);
    })->toThrow(RuntimeException::class, 'The module registry cache is invalid');

    // Feature search & lookup
    expect($registry->searchFeatures(''))->toHaveKeys(['reg-base', 'reg-mid', 'reg-top'])
        ->and($registry->searchFeatures('mid'))->toBe(['reg-mid' => ['Mid Feature']])
        ->and($registry->providingFeature('base feature'))->toHaveCount(1);

    // Diamond dependency ordering
    $resolvedNames = array_map(static fn (Manifest $m): string => $m->name(), $registry->resolve([]));
    expect($resolvedNames)->toBe(['reg-base', 'reg-mid', 'reg-top']);

    // Missing external library
    $dMissingExt = mmWriteFixture($this, 'reg-miss-ext', ['name' => 'reg-miss-ext', 'requires' => ['packages' => ['vendor/nope' => '^1.0']]]);
    expect(fn () => (new ModuleRegistry(['reg-miss-ext' => Manifest::fromFile("{$dMissingExt}/module.json")]))->resolve([]))
        ->toThrow(DependencyResolutionFailed::class, 'missing or incompatible library');

    // Incompatible installed module version
    $dBadVer = mmWriteFixture($this, 'reg-bad-ver', ['name' => 'reg-bad-ver', 'requires' => ['packages' => ['local/reg-base' => '^2.0']]]);
    expect(fn () => (new ModuleRegistry(['reg-base' => $manifests['reg-base'], 'reg-bad-ver' => Manifest::fromFile("{$dBadVer}/module.json")]))->resolve([]))
        ->toThrow(DependencyResolutionFailed::class, 'installed manifest is 1.0.0');

    // Disabled module dependency
    expect(fn () => $registry->resolve([], ['reg-base']))
        ->toThrow(DependencyResolutionFailed::class, 'requires enabled package');

    // Missing capability
    $dMissCap = mmWriteFixture($this, 'reg-miss-cap', ['name' => 'reg-miss-cap', 'requires' => ['capabilities' => ['nope.cap' => '^1.0']]]);
    expect(fn () => (new ModuleRegistry(['reg-miss-cap' => Manifest::fromFile("{$dMissCap}/module.json")]))->resolve([]))
        ->toThrow(DependencyResolutionFailed::class, 'missing capability');

    // Disabled capability owner
    expect(fn () => $registry->resolve([], ['reg-mid']))
        ->toThrow(DependencyResolutionFailed::class, 'requires enabled capability');

    // Circular dependency
    $dCycA = mmWriteFixture($this, 'cyc-a', ['name' => 'cyc-a', 'requires' => ['packages' => ['local/cyc-b' => '^1.0']]]);
    $dCycB = mmWriteFixture($this, 'cyc-b', ['name' => 'cyc-b', 'requires' => ['packages' => ['local/cyc-a' => '^1.0']]]);
    $cycReg = new ModuleRegistry([
        'cyc-a' => Manifest::fromFile("{$dCycA}/module.json"),
        'cyc-b' => Manifest::fromFile("{$dCycB}/module.json"),
    ]);
    expect(fn () => $cycReg->resolve([]))->toThrow(DependencyResolutionFailed::class, 'Circular module dependency');
});

it('covers ModuleValidator and ModuleValidationGuard across all validation rules', function (): void {
    // Compatible patch version (v1.2.3 vs 1.0.0 -> same major) & canonical package name
    $dOk = mmWriteFixture(
        $this,
        'val-ok',
        ['name' => 'val-ok', 'version' => '1.0.0', 'category' => 'presentation', 'presentation' => ['filament' => ['admin' => [ModuleManagerCovDummyPlugin::class]]]],
        ['name' => 'liberusoftware/module-liberu-val-ok', 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => 'val-ok']]],
        installed: true,
        installedVersion: 'v1.2.3',
        installedPackageOverride: 'liberusoftware/liberu-val-ok',
    );
    $okReg = new ModuleRegistry(['val-ok' => Manifest::fromFile("{$dOk}/module.json")]);
    $validator = new ModuleValidator();
    $guard = new ModuleValidationGuard($validator);
    expect($validator->validate($okReg, Application::VERSION))->toBe([]);
    $guard->ensureValid($okReg, Application::VERSION);

    // Every validation failure branch
    $dBad1 = mmWriteFixture(
        $this,
        'val-bad-1',
        [
            'name' => 'val-bad-1',
            'version' => '1.0.0',
            'provider' => stdClass::class,
            'requires' => ['php' => '^7.0', 'laravel' => '^8.0', 'packages' => ['liberusoftware/liberu-ghost' => '^1.0']],
            'presentation' => ['filament' => ['admin' => ['Missing\\PluginClass']]],
        ],
        [
            'name' => 'local/val-bad-1',
            'type' => 'library',
            'extra' => ['liberu' => ['name' => 'wrong-name']],
            'require' => [],
        ],
        installed: true,
        installedVersion: '2.0.0',
    );
    $dBad2 = mmWriteFixture(
        $this,
        'val-bad-2',
        ['name' => 'val-bad-2', 'provider' => 'Missing\\ProviderClass'],
        ['name' => 'local/val-bad-2', 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => 'val-bad-2']]],
        installed: false,
    );

    $badReg = new ModuleRegistry([
        'val-bad-1' => Manifest::fromFile("{$dBad1}/module.json"),
        'val-bad-2' => Manifest::fromFile("{$dBad2}/module.json"),
    ]);

    $errors = $validator->validate($badReg, Application::VERSION);
    $joined = implode("\n", $errors);
    expect($joined)
        ->toContain('extra.liberu.name does not match')
        ->toContain('Composer type must be liberu-module')
        ->toContain('installed Composer version does not match')
        ->toContain('Composer and manifest Liberu dependencies differ')
        ->toContain("provider must extend Laravel's ServiceProvider")
        ->toContain('PHP '.PHP_VERSION.' does not satisfy ^7.0')
        ->toContain('does not satisfy ^8.0')
        ->toContain('only presentation modules may declare Filament plugins')
        ->toContain('presentation plugin Missing\\PluginClass is not autoloadable')
        ->toContain('Composer package is not installed')
        ->toContain('provider Missing\\ProviderClass is not autoloadable');

    expect(fn () => $guard->ensureValid($badReg, Application::VERSION))
        ->toThrow(RuntimeException::class, 'Module validation failed:');
});

it('covers RegistryCache read, write, clear, and error branches', function (): void {
    $sub = "{$this->mmRoot}/cache-src";
    mkdir($sub, 0777, true);
    mmWriteFixture($this, 'cache-src/cmod', ['name' => 'cmod']);

    $cache = new RegistryCache();
    $cachePath = "{$this->mmRoot}/nested/dir/registry.cache";

    $discovered = $cache->load([$sub], false, $cachePath);
    expect($discovered->has('cmod'))->toBeTrue();

    $cache->write($discovered, $cachePath);
    expect(is_file($cachePath))->toBeTrue();

    $fromCache = $cache->load([$sub], true, $cachePath);
    expect($fromCache->has('cmod'))->toBeTrue();

    // Corrupt cache payload
    file_put_contents($cachePath, serialize('not-a-registry'));
    expect(fn () => $cache->load([$sub], true, $cachePath))
        ->toThrow(RuntimeException::class, 'The module registry cache is invalid');

    $cache->clear($cachePath);
    expect(is_file($cachePath))->toBeFalse();

    // Failing Filesystem branches for write() and clear()
    $failingFs = new class() extends Filesystem
    {
        public function put($path, $contents, $lock = false): bool|int
        {
            return false;
        }

        public function isFile($file): bool
        {
            return true;
        }

        public function delete($paths): bool
        {
            return false;
        }
    };
    $failingCache = new RegistryCache($failingFs);
    expect(fn () => $failingCache->write($discovered, "{$this->mmRoot}/fail.cache"))
        ->toThrow(RuntimeException::class, 'Unable to write the module registry cache.')
        ->and(fn () => $failingCache->clear("{$this->mmRoot}/fail.cache"))
        ->toThrow(RuntimeException::class, 'Unable to clear the module registry cache.');
});

it('covers ModuleInventory, ModuleLoader, and ModuleManagement workflows and refusals', function (): void {
    $dFeature = mmWriteFixture($this, 'feat-a', ['name' => 'feat-a', 'module_type' => 'feature', 'default_enabled' => false, 'capabilities' => ['feat.a']]);
    $dDep = mmWriteFixture($this, 'feat-b', ['name' => 'feat-b', 'module_type' => 'feature', 'default_enabled' => true, 'requires' => ['capabilities' => ['feat.a' => '^1.0']]]);
    $dInfra = mmWriteFixture($this, 'infra-a', ['name' => 'infra-a', 'module_type' => 'infrastructure']);
    $dLocked = mmWriteFixture($this, 'feat-locked', ['name' => 'feat-locked', 'module_type' => 'feature']);
    $dDisabled = mmWriteFixture($this, 'feat-disabled', ['name' => 'feat-disabled', 'module_type' => 'feature']);
    $dAuto = mmWriteFixture($this, 'feat-auto', ['name' => 'feat-auto', 'module_type' => 'feature', 'provider' => ModuleManagerCovThrowingProvider::class]);
    $dManager = mmWriteFixture($this, 'module-manager', ['name' => 'module-manager', 'module_type' => 'feature']);
    $dBroken = mmWriteFixture($this, 'feat-broken', ['name' => 'feat-broken', 'module_type' => 'feature', 'default_enabled' => false, 'provider' => 'Missing\\BrokenProvider']);

    $manifests = [
        'feat-a' => Manifest::fromFile("{$dFeature}/module.json"),
        'feat-b' => Manifest::fromFile("{$dDep}/module.json"),
        'infra-a' => Manifest::fromFile("{$dInfra}/module.json"),
        'feat-locked' => Manifest::fromFile("{$dLocked}/module.json"),
        'feat-disabled' => Manifest::fromFile("{$dDisabled}/module.json"),
        'feat-auto' => Manifest::fromFile("{$dAuto}/module.json"),
        'module-manager' => Manifest::fromFile("{$dManager}/module.json"),
        'feat-broken' => Manifest::fromFile("{$dBroken}/module.json"),
    ];

    $inventory = new ModuleInventory(
        $manifests,
        ['feat-b' => $manifests['feat-b']],
        ['unreadable-dir' => ['Syntax error']],
        ['feat-locked'],
        ['feat-locked'],
    );
    $inventory->markFailed('feat-b', 'Boom');
    expect($inventory->status('feat-b'))->toBe('invalid')
        ->and($inventory->status('feat-a'))->toBe('disabled')
        ->and($inventory->rows())->toHaveKey('unreadable-dir');

    $packages = new PackageManifest(new Filesystem(), base_path(), "{$this->mmRoot}/packages.php");
    $packages->manifest = ['local/feat-auto' => ['providers' => [ModuleManagerCovThrowingProvider::class]]];

    $mgmt = new ModuleManagement(
        $inventory,
        app(ModuleActivations::class),
        new ModuleValidator(),
        $packages,
        ['feat-a'],
        ['feat-disabled'],
    );

    $list = $mgmt->modules();
    expect($list)->not->toHaveKey('infra-a')
        ->and($list['feat-locked']['locked'])->toBe('This application requires this module.')
        ->and($list['module-manager']['locked'])->toBe('This application requires this module.')
        ->and($list['feat-disabled']['locked'])->toBe('Disabled by the MODULES_DISABLED setting.')
        ->and($list['feat-auto']['locked'])->toContain('Laravel auto-discovers this module')
        ->and($list['feat-b']['status'])->toBe('failed');

    // Enable feat-a succeeds
    $mgmt->enable('feat-a');
    expect(app(ModuleActivations::class)->all()['feat-a'])->toBeTrue();

    // Disabling feat-a fails dependency check because feat-b requires feat.a (Wait: feat-b is in problems so alreadyBroken; clear problem to trigger dependency refusal)
    $cleanInventory = new ModuleInventory($manifests, ['feat-a' => $manifests['feat-a'], 'feat-b' => $manifests['feat-b']], [], ['feat-locked']);
    $cleanMgmt = new ModuleManagement($cleanInventory, app(ModuleActivations::class), new ModuleValidator(), $packages, ['feat-a'], ['feat-disabled']);
    expect(fn () => $cleanMgmt->disable('feat-a'))
        ->toThrow(ModuleActivationRefused::class, 'requires enabled capability');

    // Refusals: missing, infrastructure, locked, and validation failure
    expect(fn () => $cleanMgmt->enable('unknown-mod'))->toThrow(ModuleActivationRefused::class, 'not installed through Composer')
        ->and(fn () => $cleanMgmt->enable('infra-a'))->toThrow(ModuleActivationRefused::class, 'infrastructure, not a feature module')
        ->and(fn () => $cleanMgmt->disable('feat-locked'))->toThrow(ModuleActivationRefused::class, 'This application requires this module.')
        ->and(fn () => $cleanMgmt->enable('feat-broken'))->toThrow(ModuleActivationRefused::class, 'not autoloadable');

    // ModuleLoader: cached boot, audit, required failures, cascade exclusion, and unknown owner
    $sub = "{$this->mmRoot}/loader-sub";
    mkdir($sub, 0777, true);
    mmWriteFixture($this, 'loader-sub/good', ['name' => 'good']);
    mmWriteFixture($this, 'loader-sub/bad-base', ['name' => 'bad-base', 'provider' => 'Missing\\Prov', 'capabilities' => ['bad.cap']]);
    mmWriteFixture($this, 'loader-sub/bad-child', ['name' => 'bad-child', 'requires' => ['capabilities' => ['bad.cap' => '^1.0']]]);

    $loader = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$sub], [], [], []);
    $loaded = $loader->load();
    expect($loaded->isBooted('good'))->toBeTrue()
        ->and($loaded->status('bad-base'))->toBe('invalid')
        ->and($loaded->status('bad-child'))->toBe('invalid')
        ->and($loader->audit())->toHaveKey('bad-base');

    // Required missing & required failing
    $reqMissing = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$sub], [], [], ['absent']);
    expect(fn () => $reqMissing->load())->toThrow(RequiredModuleFailed::class, 'it is not installed.');

    $reqBroken = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$sub], [], [], ['bad-base']);
    expect(fn () => $reqBroken->load())->toThrow(RequiredModuleFailed::class, 'not autoloadable');

    // Cached load + unresolvable owner
    $dUnk = mmWriteFixture($this, 'unk-mod', ['name' => 'unk-mod', 'requires' => ['packages' => ['local/nope' => '^1.0']]]);
    $unkCache = "{$this->mmRoot}/unk.cache";
    (new RegistryCache())->write(new ModuleRegistry(['BAD KEY' => Manifest::fromFile("{$dUnk}/module.json")]), $unkCache);
    $unkLoader = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$sub], [], [], [], true, $unkCache);
    expect(fn () => $unkLoader->load())->toThrow(RuntimeException::class, 'Module selection could not settle:');
});

it('covers ModuleManagerServiceProvider (standard & isolating) and all Artisan commands', function (): void {
    $sub = "{$this->mmRoot}/sp-sub";
    mkdir($sub, 0777, true);
    mmWriteFixture($this, 'sp-sub/good', ['name' => 'good', 'features' => ['Good Feature']]);
    mmWriteFixture($this, 'sp-sub/module-manager', ['name' => 'module-manager', 'features' => ['Manager']]);

    $cachePath = "{$this->mmRoot}/sp.cache";
    config([
        'modules.paths' => [$sub],
        'modules.enabled' => [],
        'modules.disabled' => [],
        'modules.required' => [],
        'modules.isolate_failures' => false,
        'modules.cache' => false,
        'modules.cache_path' => $cachePath,
    ]);

    $provider = $this->app->register(ModuleManagerServiceProvider::class, true);
    $provider->boot();

    expect($this->app->bound('mm_cov.good_booted'))->toBeTrue()
        ->and($this->app->make(ModuleManagement::class))->toBeInstanceOf(ModuleManagement::class);

    // Commands on clean state
    expect(Artisan::call('module:list'))->toBe(0)
        ->and(Artisan::output())->toContain('good', 'booted')
        ->and(Artisan::call('module:list', ['--json' => true]))->toBe(0)
        ->and(Artisan::call('module:features', ['query' => 'good']))->toBe(0)
        ->and(Artisan::output())->toContain('Good Feature')
        ->and(Artisan::call('module:status', ['name' => 'good']))->toBe(0)
        ->and(Artisan::call('module:status', ['name' => 'missing-mod']))->toBe(1)
        ->and(Artisan::call('module:cache'))->toBe(0)
        ->and(is_file($cachePath))->toBeTrue()
        ->and(Artisan::call('module:clear'))->toBe(0)
        ->and(is_file($cachePath))->toBeFalse()
        ->and(Artisan::call('module:validate'))->toBe(0)
        ->and(Artisan::output())->toContain('All discovered modules are valid.');

    // Auto-discovery warning vs --strict failure
    $packages = new PackageManifest(new Filesystem(), base_path(), "{$this->mmRoot}/sp-packages.php");
    $packages->manifest = ['local/good' => ['providers' => [ModuleManagerCovGoodProvider::class]]];
    $this->app->instance(PackageManifest::class, $packages);

    expect(Artisan::call('module:validate'))->toBe(0)
        ->and(Artisan::output())->toContain('bypasses the module manager')
        ->and(Artisan::call('module:validate', ['--strict' => true]))->toBe(1);

    // Isolating mode: optional throwing provider is marked failed and logged, required throwing provider throws
    Log::spy();
    mmWriteFixture($this, 'sp-sub/throws', ['name' => 'throws', 'provider' => ModuleManagerCovThrowingProvider::class]);
    mmWriteFixture($this, 'sp-sub/broken-json', rawManifest: '{ invalid');

    config([
        'modules.paths' => [$sub],
        'modules.isolate_failures' => true,
        'modules.required' => ['good'],
        'modules.disabled' => ['good'],
    ]);
    $this->app->register(ModuleManagerServiceProvider::class, true);

    $inventory = $this->app->make(ModuleInventory::class);
    expect($inventory->status('throws'))->toBe('invalid')
        ->and($inventory->problems['throws'][0])->toContain('cov provider exploded')
        ->and($inventory->refusedDisables)->toBe(['good']);
    Log::shouldHaveReceived('warning')->atLeast()->once();

    expect(Artisan::call('module:validate'))->toBe(1)
        ->and(Artisan::output())->toContain('the disabled list names a required module')
        ->and(Artisan::call('module:status', ['name' => 'broken-json']))->toBe(0)
        ->and(Artisan::call('module:cache'))->toBe(1);

    // Required provider throwing in isolating mode rethrows RequiredModuleFailed
    config(['modules.required' => ['throws'], 'modules.disabled' => []]);
    expect(fn () => $this->app->register(ModuleManagerServiceProvider::class, true))
        ->toThrow(RequiredModuleFailed::class, 'cov provider exploded');
});
