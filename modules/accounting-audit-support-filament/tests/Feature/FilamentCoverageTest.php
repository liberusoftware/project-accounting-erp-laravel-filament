<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\AuditSupportFilament\AuditSupportFilamentPlugin;
use Liberu\Accounting\AuditSupportFilament\AuditSupportFilamentServiceProvider;
use Liberu\Accounting\AuditSupportFilament\Resources\AuditRequestResource;
use Liberu\Accounting\AuditSupportFilament\Resources\Pages\CreateAuditRequest;
use Liberu\Accounting\AuditSupportFilament\Resources\Pages\ListAuditRequests;

it('registers and configures accounting-audit-support-filament filament resources', function (): void {
    $provider = $this->app->register(AuditSupportFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AuditSupportFilamentServiceProvider::class);
    $plugin = AuditSupportFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-audit-support-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListAuditRequests();
    expect(AuditRequestResource::getPages())->toBeArray();
    expect(AuditRequestResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(AuditRequestResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AuditRequestResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateAuditRequest(), CreateAuditRequest::class)();
    expect($mutated)->toBeArray();
});
