<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\ChartOfAccountsFilament\ChartOfAccountsFilamentPlugin;
use Liberu\Accounting\ChartOfAccountsFilament\ChartOfAccountsFilamentServiceProvider;
use Liberu\Accounting\ChartOfAccountsFilament\Resources\AccountResource;
use Liberu\Accounting\ChartOfAccountsFilament\Resources\AccountResource\Pages\CreateAccount;
use Liberu\Accounting\ChartOfAccountsFilament\Resources\AccountResource\Pages\EditAccount;
use Liberu\Accounting\ChartOfAccountsFilament\Resources\AccountResource\Pages\ListAccounts;
use Liberu\Accounting\Core\Models\LegalEntity;

it('registers and configures accounting-chart-of-accounts-filament filament resources', function (): void {
    $provider = $this->app->register(ChartOfAccountsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ChartOfAccountsFilamentServiceProvider::class);
    $plugin = ChartOfAccountsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-chart-of-accounts-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListAccounts();
    expect(AccountResource::getPages())->toBeArray();
    expect(AccountResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(AccountResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $hdrActions = Closure::bind(fn () => $this->getHeaderActions(), new ListAccounts(), ListAccounts::class)();
    expect($hdrActions)->toBeArray();
});

it('covers CreateAccount and EditAccount record handlers', function (): void {
    $entity = LegalEntity::query()->create(['name' => 'COA Ent', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);
    $createPage = new CreateAccount();
    $account = Closure::bind(fn () => $this->handleRecordCreation([
        'legal_entity_id' => $entity->getKey(),
        'code' => '1010',
        'name' => 'Petty Cash',
        'type' => 'asset',
    ]), $createPage, $createPage::class)();
    expect($account)->not->toBeNull();

    $editPage = new EditAccount();
    $updated = Closure::bind(fn () => $this->handleRecordUpdate($account, [
        'legal_entity_id' => $entity->getKey(),
        'code' => '1010',
        'name' => 'Petty Cash Updated',
        'type' => 'asset',
    ]), $editPage, $editPage::class)();
    expect($updated->name)->toBe('Petty Cash Updated');

    expect(fn () => Closure::bind(fn () => $this->handleRecordUpdate($entity, []), $editPage, $editPage::class)())
        ->toThrow(InvalidArgumentException::class);
});
