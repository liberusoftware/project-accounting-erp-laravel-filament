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
use Liberu\Accounting\BankRulesFilament\BankRulesFilamentPlugin;
use Liberu\Accounting\BankRulesFilament\BankRulesFilamentServiceProvider;
use Liberu\Accounting\BankRulesFilament\Resources\BankRuleResource;
use Livewire\Component;

it('registers and configures accounting-bank-rules-filament filament resources', function (): void {
    $provider = $this->app->register(BankRulesFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BankRulesFilamentServiceProvider::class);
    $plugin = BankRulesFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-bank-rules-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new class() extends Component implements HasActions, HasSchemas, HasTable
    {
        use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;
    };
    expect(BankRuleResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(BankRuleResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
