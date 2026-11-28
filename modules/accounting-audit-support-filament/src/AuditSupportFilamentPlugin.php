<?php

declare(strict_types=1);

namespace Liberu\Accounting\AuditSupportFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\AuditSupportFilament\Resources\AuditRequestResource;

final class AuditSupportFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'accounting-audit-support';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([AuditRequestResource::class]);
    }

    public function boot(Panel $panel): void {}
}
