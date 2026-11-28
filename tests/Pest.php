<?php

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Accounting\Erp\Models\Team as ErpTeam;
use Liberu\PackageTestbench\PackageTestCase;
use Tests\Support\ErpTeamFactory;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// The runtime-selection rule reads config('modules.*'), which needs a booted container.
pest()->extend(TestCase::class)->in('Architecture');

if (is_dir(dirname(__DIR__).'/modules')) {
    if (! class_exists(PackageTestCase::class)) {
        require_once __DIR__.'/Support/PackageTestCaseShim.php';
    }

    $modulesDir = dirname(__DIR__).'/modules';
    $moduleTestPrefixes = [];

    foreach (glob($modulesDir.'/*/composer.json') ?: [] as $composerFile) {
        $data = json_decode((string) file_get_contents($composerFile), true);
        if (! is_array($data)) {
            continue;
        }
        $modRoot = dirname($composerFile);
        foreach ($data['autoload-dev']['psr-4'] ?? [] as $prefix => $paths) {
            foreach ((array) $paths as $relPath) {
                $moduleTestPrefixes[$prefix][] = $modRoot.'/'.trim((string) $relPath, '/').'/';
            }
        }
        if (is_dir($modRoot.'/tests')) {
            $moduleTestPrefixes['Tests\\'][] = $modRoot.'/tests/';
        }
        foreach (glob($modRoot.'/tests/Helpers.php') ?: [] as $helperFile) {
            require_once $helperFile;
        }
        foreach (glob($modRoot.'/tests/Fixtures/*.php') ?: [] as $fixtureFile) {
            require_once $fixtureFile;
        }
    }

    spl_autoload_register(static function (string $class) use ($moduleTestPrefixes): void {
        foreach ($moduleTestPrefixes as $prefix => $dirs) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            foreach ($dirs as $dir) {
                $file = $dir.$relative;
                if (is_file($file)) {
                    require_once $file;

                    return;
                }
            }
        }
    });

    $selfBoundRootOnly = [
        'passkeys',
        'payment-adyen',
        'payment-contracts',
        'payment-core',
        'payment-gocardless',
        'payment-mollie',
        'payment-paddle',
        'payment-paypal',
        'payment-square',
        'payment-stripe',
    ];
    $moduleTestDirs = [];
    foreach (glob($modulesDir.'/*/tests', GLOB_ONLYDIR) ?: [] as $testDir) {
        $modName = basename(dirname($testDir));
        if ($modName === 'roles-permissions-filament') {
            continue;
        }
        if (in_array($modName, $selfBoundRootOnly, true)) {
            if (is_dir($testDir.'/Feature')) {
                $moduleTestDirs[] = '../modules/'.$modName.'/tests/Feature';
            }

            continue;
        }
        $moduleTestDirs[] = '../modules/'.$modName.'/tests';
    }

    if ($moduleTestDirs !== []) {
        pest()->extend(TestCase::class)
            ->use(RefreshDatabase::class)
            ->beforeEach(function (): void {
                Model::unguard();
                Model::preventLazyLoading(false);
                Model::preventSilentlyDiscardingAttributes(false);
                Model::preventAccessingMissingAttributes(false);
                Model::automaticallyEagerLoadRelationships(false);
                Factory::guessFactoryNamesUsing(static function (string $modelName): string {
                    if ($modelName === ErpTeam::class) {
                        return ErpTeamFactory::class;
                    }

                    $appNamespace = 'App\\';
                    $modelName = str_starts_with($modelName, $appNamespace.'Models\\')
                        ? substr($modelName, strlen($appNamespace.'Models\\'))
                        : substr($modelName, strlen($appNamespace));

                    return Factory::$namespace.$modelName.'Factory';
                });
            })
            ->afterEach(function (): void {
                Model::reguard();
            })
            ->in(...$moduleTestDirs);
    }
}
