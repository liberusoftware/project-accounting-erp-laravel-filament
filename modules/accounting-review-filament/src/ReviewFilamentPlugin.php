<?php

declare(strict_types=1);

namespace Liberu\Accounting\ReviewFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\ReviewFilament\Resources\ReviewItemResource;

final class ReviewFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'accounting-review';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([Resources\ReviewItemResource::class]);
    }

    public function boot(Panel $panel): void {}
}
