<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Accounting\Erp\Models\Bill;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Models\VendorCredit;
use Liberu\Accounting\SupplierPortalFilament\Resources\Bills\Pages\ListPortalBills;
use Liberu\Accounting\SupplierPortalFilament\Resources\Bills\PortalBillResource;
use Liberu\Accounting\SupplierPortalFilament\Resources\PortalResourceResource;
use Liberu\Accounting\SupplierPortalFilament\Resources\PortalResourceResource\Pages\ListPortalResources;
use Liberu\Accounting\SupplierPortalFilament\Resources\VendorCredits\Pages\ListPortalVendorCredits;
use Liberu\Accounting\SupplierPortalFilament\Resources\VendorCredits\PortalVendorCreditResource;
use Liberu\Accounting\SupplierPortalFilament\SupplierPortalFilamentPlugin;
use Liberu\Accounting\SupplierPortalFilament\SupplierPortalFilamentServiceProvider;
use Liberu\Accounting\SupplierPortalFilament\Widgets\PortalBalanceWidget;

it('registers and configures accounting-supplier-portal-filament filament resources', function (): void {
    $provider = $this->app->register(SupplierPortalFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(SupplierPortalFilamentServiceProvider::class);

    $plugin = SupplierPortalFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-supplier-portal-filament');
    $plugin->register($panel);
    $plugin->boot($panel);

    $vendorPanel = Panel::make()->id('vendor');
    $plugin->register($vendorPanel);
    $plugin->boot($vendorPanel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();

    $host = new ListPortalResources();
    expect(PortalResourceResource::getPages())->toBeArray();
    expect(PortalResourceResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PortalResourceResource::table(Table::make($host)))->toBeInstanceOf(Table::class);

    $vendor = new Vendor();
    $vendor->vendor_id = 77;
    $this->actingAs($vendor, 'vendor');

    $billsHost = new ListPortalBills();
    expect($billsHost)->toBeInstanceOf(ListPortalBills::class);
    expect(PortalBillResource::getPages())->toBeArray();
    expect(PortalBillResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PortalBillResource::table(Table::make($billsHost)))->toBeInstanceOf(Table::class);

    $bill = new Bill();
    expect(PortalBillResource::canCreate())->toBeFalse();
    expect(PortalBillResource::getCreateAuthorizationResponse()->denied())->toBeTrue();
    expect(PortalBillResource::canEdit($bill))->toBeFalse();
    expect(PortalBillResource::getEditAuthorizationResponse($bill)->denied())->toBeTrue();
    expect(PortalBillResource::canDelete($bill))->toBeFalse();
    expect(PortalBillResource::getDeleteAuthorizationResponse($bill)->denied())->toBeTrue();

    $allowBillResource = new class() extends PortalBillResource
    {
        public static function canCreate(): bool
        {
            return true;
        }

        public static function canEdit(Model $record): bool
        {
            return true;
        }

        public static function canDelete(Model $record): bool
        {
            return true;
        }
    };
    expect($allowBillResource::getCreateAuthorizationResponse())->toBeInstanceOf(Response::class);
    expect($allowBillResource::getEditAuthorizationResponse($bill))->toBeInstanceOf(Response::class);
    expect($allowBillResource::getDeleteAuthorizationResponse($bill))->toBeInstanceOf(Response::class);

    $creditsHost = new ListPortalVendorCredits();
    expect($creditsHost)->toBeInstanceOf(ListPortalVendorCredits::class);
    expect(PortalVendorCreditResource::getPages())->toBeArray();
    expect(PortalVendorCreditResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PortalVendorCreditResource::table(Table::make($creditsHost)))->toBeInstanceOf(Table::class);

    $credit = new VendorCredit();
    expect(PortalVendorCreditResource::canCreate())->toBeFalse();
    expect(PortalVendorCreditResource::getCreateAuthorizationResponse()->denied())->toBeTrue();
    expect(PortalVendorCreditResource::canEdit($credit))->toBeFalse();
    expect(PortalVendorCreditResource::getEditAuthorizationResponse($credit)->denied())->toBeTrue();
    expect(PortalVendorCreditResource::canDelete($credit))->toBeFalse();
    expect(PortalVendorCreditResource::getDeleteAuthorizationResponse($credit)->denied())->toBeTrue();

    $allowCreditResource = new class() extends PortalVendorCreditResource
    {
        public static function canCreate(): bool
        {
            return true;
        }

        public static function canEdit(Model $record): bool
        {
            return true;
        }

        public static function canDelete(Model $record): bool
        {
            return true;
        }
    };
    expect($allowCreditResource::getCreateAuthorizationResponse())->toBeInstanceOf(Response::class);
    expect($allowCreditResource::getEditAuthorizationResponse($credit))->toBeInstanceOf(Response::class);
    expect($allowCreditResource::getDeleteAuthorizationResponse($credit))->toBeInstanceOf(Response::class);

    $stats = Closure::bind(
        fn (): array => $this->getStats(),
        new PortalBalanceWidget(),
        PortalBalanceWidget::class,
    )();
    expect($stats)->toBeArray()->toHaveCount(2);
});
