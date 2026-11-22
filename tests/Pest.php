<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// The runtime-selection rule reads config('modules.*'), which needs a booted container.
pest()->extend(TestCase::class)->in('Architecture');

if (is_dir(dirname(__DIR__).'/modules')) {
    if (! class_exists(\Liberu\PackageTestbench\PackageTestCase::class)) {
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

    pest()->extend(TestCase::class)
        ->use(RefreshDatabase::class)
        ->in('../modules');
}
