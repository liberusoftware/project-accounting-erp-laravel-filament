<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\PayrollPayments\Enums\PaymentStatus;
use Liberu\Accounting\PayrollPayments\Models\PayrollPaymentBatch;
use Liberu\Accounting\PayrollPaymentsFilament\PayrollPaymentsFilamentPlugin;
use Liberu\Accounting\PayrollPaymentsFilament\PayrollPaymentsFilamentServiceProvider;
use Liberu\Accounting\PayrollPaymentsFilament\Resources\PayrollPaymentBatchResource;
use Liberu\Accounting\PayrollPaymentsFilament\Resources\PayrollPaymentBatchResource\Pages\ListPayrollPaymentBatches;

it('registers and configures accounting-payroll-payments-filament filament resources', function (): void {
    $provider = $this->app->register(PayrollPaymentsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    $plugin = PayrollPaymentsFilamentPlugin::make();
    $panel = Panel::make()->id('test-pp');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();

    $host = new ListPayrollPaymentBatches();
    expect(PayrollPaymentBatchResource::getPages())->toBeArray();
    expect(PayrollPaymentBatchResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(PayrollPaymentBatchResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    $table = PayrollPaymentBatchResource::table(Table::make($host));
    expect($table)->toBeInstanceOf(Table::class);

    $batch = PayrollPaymentBatch::query()->create([
        'team_id' => 1,
        'batch_ref' => 'FIL-BATCH-1',
        'currency' => 'GBP',
        'net_pay_amount' => 500,
        'liability_amount' => 100,
        'status' => PaymentStatus::Draft,
    ]);
    $action = $table->getAction('transition');
    expect($action)->not->toBeNull();
    $action->record($batch);
    $action->call(['data' => ['status' => 'approved']]);
    expect($batch->refresh()->status)->toBe(PaymentStatus::Approved);
});
