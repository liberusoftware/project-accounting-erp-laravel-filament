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
use Liberu\Accounting\BankFeedsFilament\BankFeedsFilamentPlugin;
use Liberu\Accounting\BankFeedsFilament\BankFeedsFilamentServiceProvider;
use Liberu\Accounting\BankFeedsFilament\Resources\BankFeedConnectionResource;
use Livewire\Component;

it('registers and configures accounting-bank-feeds-filament filament resources', function (): void {
    $provider = $this->app->register(BankFeedsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BankFeedsFilamentServiceProvider::class);
    $plugin = BankFeedsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-bank-feeds-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new class() extends Component implements HasActions, HasSchemas, HasTable
    {
        use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;
    };
    expect(BankFeedConnectionResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(BankFeedConnectionResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
