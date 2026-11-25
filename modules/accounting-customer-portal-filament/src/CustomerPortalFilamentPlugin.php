<?php

declare(strict_types=1);

namespace Liberu\Accounting\CustomerPortalFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\CustomerPortalFilament\Resources\Invoices\PortalInvoiceResource;
use Liberu\Accounting\CustomerPortalFilament\Widgets\PortalBalanceWidget;

final class CustomerPortalFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'module-accounting-customer-portal-filament';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'customer') {
            $panel->resources([PortalInvoiceResource::class])
                ->widgets([PortalBalanceWidget::class]);
        }
    }

    public function boot(Panel $panel): void {}
}
