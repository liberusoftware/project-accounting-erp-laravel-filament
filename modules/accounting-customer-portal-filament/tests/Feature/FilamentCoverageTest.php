<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Accounting\CustomerPortalFilament\CustomerPortalFilamentPlugin;
use Liberu\Accounting\CustomerPortalFilament\CustomerPortalFilamentServiceProvider;
use Liberu\Accounting\CustomerPortalFilament\Pages\Auth\CustomerLogin;
use Liberu\Accounting\CustomerPortalFilament\Resources\Invoices\Pages\ListPortalInvoices;
use Liberu\Accounting\CustomerPortalFilament\Resources\Invoices\PortalInvoiceResource;
use Liberu\Accounting\CustomerPortalFilament\Widgets\PortalBalanceWidget;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Invoice;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('registers and configures accounting-customer-portal-filament filament resources', function (): void {
    $provider = $this->app->register(CustomerPortalFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomerPortalFilamentServiceProvider::class);

    $plugin = CustomerPortalFilamentPlugin::make();
    $otherPanel = Panel::make()->id('test-accounting-customer-portal-filament');
    $plugin->register($otherPanel);
    $plugin->boot($otherPanel);

    $customerPanel = Panel::make()->id('customer');
    $plugin->register($customerPanel);
    $plugin->boot($customerPanel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();

    $credentials = Closure::bind(
        fn (): array => $this->getCredentialsFromFormData(['email' => 'customer@example.com', 'password' => 'secret']),
        new CustomerLogin(),
        CustomerLogin::class,
    )();
    expect($credentials)->toBe([
        'customer_email' => 'customer@example.com',
        'password' => 'secret',
    ]);

    $customer = new Customer();
    $customer->id = 42;
    $this->actingAs($customer, 'customer');

    $host = new ListPortalInvoices();
    expect($host)->toBeInstanceOf(ListPortalInvoices::class);
    expect(PortalInvoiceResource::getPages())->toBeArray();
    expect(PortalInvoiceResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PortalInvoiceResource::infolist(Schema::make($host)))->toBeInstanceOf(Schema::class);

    $invoice = new Invoice();
    expect(PortalInvoiceResource::canCreate())->toBeFalse();
    expect(PortalInvoiceResource::getCreateAuthorizationResponse())->toBeInstanceOf(Response::class);
    expect(PortalInvoiceResource::getCreateAuthorizationResponse()->denied())->toBeTrue();
    expect(PortalInvoiceResource::canEdit($invoice))->toBeFalse();
    expect(PortalInvoiceResource::getEditAuthorizationResponse($invoice))->toBeInstanceOf(Response::class);
    expect(PortalInvoiceResource::getEditAuthorizationResponse($invoice)->denied())->toBeTrue();
    expect(PortalInvoiceResource::canDelete($invoice))->toBeFalse();
    expect(PortalInvoiceResource::getDeleteAuthorizationResponse($invoice))->toBeInstanceOf(Response::class);
    expect(PortalInvoiceResource::getDeleteAuthorizationResponse($invoice)->denied())->toBeTrue();

    $allowResource = new class() extends PortalInvoiceResource
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
    expect($allowResource::getCreateAuthorizationResponse())->toBeInstanceOf(Response::class);
    expect($allowResource::getEditAuthorizationResponse($invoice))->toBeInstanceOf(Response::class);
    expect($allowResource::getDeleteAuthorizationResponse($invoice))->toBeInstanceOf(Response::class);

    $table = PortalInvoiceResource::table(Table::make($host));
    expect($table)->toBeInstanceOf(Table::class);

    $downloadAction = $table->getAction('download');
    expect($downloadAction)->not->toBeNull();

    $fakeInvoice = new class() extends Invoice
    {
        public function generatePDF(): string
        {
            return 'pdf-content';
        }
    };
    $fakeInvoice->customer_id = 999;
    $downloadAction->record($fakeInvoice);
    expect(fn () => $downloadAction->call())->toThrow(HttpException::class);

    $fakeInvoice->customer_id = 42;
    $downloadAction->record($fakeInvoice);
    expect($downloadAction->call())->toBe('pdf-content');

    $stats = Closure::bind(
        fn (): array => $this->getStats(),
        new PortalBalanceWidget(),
        PortalBalanceWidget::class,
    )();
    expect($stats)->toBeArray()->toHaveCount(2);
});
