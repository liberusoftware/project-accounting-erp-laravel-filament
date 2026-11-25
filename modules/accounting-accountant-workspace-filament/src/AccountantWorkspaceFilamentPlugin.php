<?php

declare(strict_types=1);

namespace Liberu\Accounting\AccountantWorkspaceFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\AccountantWorkspaceFilament\Resources\WorkspaceItemResource;

final class AccountantWorkspaceFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'accounting-accountant-workspace';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([Resources\WorkspaceItemResource::class]);
    }

    public function boot(Panel $panel): void {}
}
