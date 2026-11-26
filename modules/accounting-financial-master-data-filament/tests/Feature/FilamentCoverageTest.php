<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\FinancialMasterDataFilament\FinancialMasterDataFilamentPlugin;
use Liberu\Accounting\FinancialMasterDataFilament\FinancialMasterDataFilamentServiceProvider;
use Liberu\Accounting\FinancialMasterDataFilament\Resources\ItemServiceResource;
use Liberu\Accounting\FinancialMasterDataFilament\Resources\ItemServiceResource\Pages\CreateItemService;
use Liberu\Accounting\FinancialMasterDataFilament\Resources\ItemServiceResource\Pages\EditItemService;
use Liberu\Accounting\FinancialMasterDataFilament\Resources\ItemServiceResource\Pages\ListItemServices;
use Liberu\Accounting\FinancialMasterDataFilament\Resources\PartyResource;
use Liberu\Accounting\FinancialMasterDataFilament\Resources\PartyResource\Pages\CreateParty;
use Liberu\Accounting\FinancialMasterDataFilament\Resources\PartyResource\Pages\EditParty;
use Liberu\Accounting\FinancialMasterDataFilament\Resources\PartyResource\Pages\ListParties;

it('registers and configures accounting-financial-master-data-filament filament resources', function (): void {
    $provider = $this->app->register(FinancialMasterDataFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(FinancialMasterDataFilamentServiceProvider::class);
    $plugin = FinancialMasterDataFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-financial-master-data-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListItemServices();
    expect(ItemServiceResource::getPages())->toBeArray();
    expect(ItemServiceResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ItemServiceResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    expect(PartyResource::getPages())->toBeArray();
    expect(PartyResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PartyResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $hdrActions = Closure::bind(fn () => $this->getHeaderActions(), new ListItemServices(), ListItemServices::class)();
    expect($hdrActions)->toBeArray();
    $hdrActions = Closure::bind(fn () => $this->getHeaderActions(), new ListParties(), ListParties::class)();
    expect($hdrActions)->toBeArray();
    expect(new CreateItemService())->toBeInstanceOf(CreateItemService::class);
    expect(new EditItemService())->toBeInstanceOf(EditItemService::class);
    expect(new CreateParty())->toBeInstanceOf(CreateParty::class);
    expect(new EditParty())->toBeInstanceOf(EditParty::class);
});
