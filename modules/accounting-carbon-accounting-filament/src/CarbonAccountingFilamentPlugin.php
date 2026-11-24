<?php

declare(strict_types=1);

namespace Liberu\Accounting\CarbonAccountingFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\CarbonAccountingFilament\Resources\CarbonActivityResource;

final class CarbonAccountingFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'accounting-carbon-accounting';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([Resources\CarbonActivityResource::class]);
    }

    public function boot(Panel $panel): void {}
}
