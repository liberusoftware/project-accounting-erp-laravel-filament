<?php

declare(strict_types=1);

namespace Liberu\Accounting\AssetEventsFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\AssetEventsFilament\Resources\AssetEventResource;

final class AssetEventsFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'accounting-asset-events';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([Resources\AssetEventResource::class]);
    }

    public function boot(Panel $panel): void {}
}
