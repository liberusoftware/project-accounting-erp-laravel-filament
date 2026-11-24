<?php

declare(strict_types=1);

namespace Liberu\Accounting\BusinessInsightsFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\BusinessInsightsFilament\Resources\InsightSnapshotResource;

final class BusinessInsightsFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'accounting-business-insights';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([Resources\InsightSnapshotResource::class]);
    }

    public function boot(Panel $panel): void {}
}
