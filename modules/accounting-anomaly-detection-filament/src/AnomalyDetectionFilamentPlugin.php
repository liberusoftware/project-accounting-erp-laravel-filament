<?php

declare(strict_types=1);

namespace Liberu\Accounting\AnomalyDetectionFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\AnomalyDetectionFilament\Resources\AnomalyResource;

final class AnomalyDetectionFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'accounting-anomaly-detection';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([AnomalyResource::class]);
    }

    public function boot(Panel $panel): void {}
}
