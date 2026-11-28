<?php

declare(strict_types=1);

namespace Liberu\Foundation\OrganizationsFilament\App\Resources {
    if (! function_exists(__NAMESPACE__.'\\class_exists')) {
        function class_exists(string $class, bool $autoload = true): bool
        {
            if (! empty($GLOBALS['__coverage_shim_hide_classes'][$class])) {
                return false;
            }

            return \class_exists($class, $autoload);
        }
    }
}

namespace Liberu\Foundation\Webhooks\Support {
    if (! function_exists(__NAMESPACE__.'\\extension_loaded')) {
        function extension_loaded(string $extension): bool
        {
            if (! empty($GLOBALS['__coverage_shim_hide_extensions'][$extension])) {
                return false;
            }

            return \extension_loaded($extension);
        }
    }
}
