<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\RecurringTransactionsFilament\RecurringTransactionsFilamentPlugin;
use Liberu\Accounting\RecurringTransactionsFilament\RecurringTransactionsFilamentServiceProvider;
use Liberu\Accounting\RecurringTransactionsFilament\Resources\Pages\ListRecurringTemplates;
use Liberu\Accounting\RecurringTransactionsFilament\Resources\RecurringTemplateResource;

it('registers and configures accounting-recurring-transactions-filament filament resources', function (): void {
    $provider = $this->app->register(RecurringTransactionsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(RecurringTransactionsFilamentServiceProvider::class);
    $plugin = RecurringTransactionsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-recurring-transactions-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListRecurringTemplates();
    expect(RecurringTemplateResource::getPages())->toBeArray();
    expect(RecurringTemplateResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(RecurringTemplateResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(RecurringTemplateResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
