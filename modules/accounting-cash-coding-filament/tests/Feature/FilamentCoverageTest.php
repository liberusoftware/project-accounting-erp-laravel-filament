<?php

declare(strict_types=1);

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Panel;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Liberu\Accounting\CashCodingFilament\CashCodingFilamentPlugin;
use Liberu\Accounting\CashCodingFilament\CashCodingFilamentServiceProvider;
use Liberu\Accounting\CashCodingFilament\Resources\CashCodingBatchResource;
use Livewire\Component;

it('registers and configures accounting-cash-coding-filament filament resources', function (): void {
    $provider = $this->app->register(CashCodingFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CashCodingFilamentServiceProvider::class);
    $plugin = CashCodingFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-cash-coding-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new class() extends Component implements HasActions, HasSchemas, HasTable
    {
        use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;
    };
    expect(CashCodingBatchResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(CashCodingBatchResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
