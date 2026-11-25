<?php

declare(strict_types=1);

namespace Liberu\Accounting\SupplierPortalFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Accounting\SupplierPortalFilament\Resources\Bills\PortalBillResource;
use Liberu\Accounting\SupplierPortalFilament\Resources\PortalResourceResource;
use Liberu\Accounting\SupplierPortalFilament\Resources\VendorCredits\PortalVendorCreditResource;
use Liberu\Accounting\SupplierPortalFilament\Widgets\PortalBalanceWidget;

final class SupplierPortalFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return new self();
    }

    public function getId(): string
    {
        return 'accounting-supplier-portal';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'vendor') {
            $panel->resources([PortalBillResource::class, PortalVendorCreditResource::class])
                ->widgets([PortalBalanceWidget::class]);

            return;
        }

        $panel->resources([PortalResourceResource::class]);
    }

    public function boot(Panel $panel): void {}
}
