<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\WithholdingTaxFilament\Resources\Pages\ListWithholdingTaxRules;
use Liberu\Accounting\WithholdingTaxFilament\Resources\WithholdingTaxRuleResource;
use Liberu\Accounting\WithholdingTaxFilament\WithholdingTaxFilamentPlugin;
use Liberu\Accounting\WithholdingTaxFilament\WithholdingTaxFilamentServiceProvider;

it('registers and configures accounting-withholding-tax-filament filament resources', function (): void {
    $provider = $this->app->register(WithholdingTaxFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(WithholdingTaxFilamentServiceProvider::class);
    $plugin = WithholdingTaxFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-withholding-tax-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListWithholdingTaxRules;
    expect(WithholdingTaxRuleResource::getPages())->toBeArray();
    expect(WithholdingTaxRuleResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(WithholdingTaxRuleResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(WithholdingTaxRuleResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
