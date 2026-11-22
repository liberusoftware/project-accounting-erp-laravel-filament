<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\DimensionsFilament\DimensionsFilamentPlugin;
use Liberu\Accounting\DimensionsFilament\DimensionsFilamentServiceProvider;
use Liberu\Accounting\DimensionsFilament\Resources\DimensionResource;
use Liberu\Accounting\DimensionsFilament\Resources\DimensionResource\Pages\CreateDimension;
use Liberu\Accounting\DimensionsFilament\Resources\DimensionResource\Pages\EditDimension;
use Liberu\Accounting\DimensionsFilament\Resources\DimensionResource\Pages\ListDimensions;

it('registers and configures accounting-dimensions-and-tracking-filament filament resources', function (): void {
    $provider = $this->app->register(DimensionsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    $plugin = DimensionsFilamentPlugin::make();
    $panel = Panel::make()->id('test-dim');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();

    $host = new ListDimensions();
    expect(DimensionResource::getPages())->toBeArray();
    expect(DimensionResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(DimensionResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(DimensionResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    expect(Closure::bind(fn () => $this->getHeaderActions(), $host, $host::class)())->toBeArray();

    $entity = LegalEntity::query()->create(['name' => 'Dim Ent', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);
    $createPage = new CreateDimension();
    $dim = Closure::bind(fn () => $this->handleRecordCreation([
        'kind' => 'department',
        'code' => 'DEPT',
        'name' => 'Department',
    ]), $createPage, $createPage::class)();
    expect($dim)->not->toBeNull();

    $editPage = new EditDimension();
    $updated = Closure::bind(fn () => $this->handleRecordUpdate($dim, [
        'kind' => 'department',
        'code' => 'DEPT',
        'name' => 'Department Updated',
    ]), $editPage, $editPage::class)();
    expect($updated->name)->toBe('Department Updated');

    expect(fn () => Closure::bind(fn () => $this->handleRecordUpdate($entity, []), $editPage, $editPage::class)())
        ->toThrow(InvalidArgumentException::class);
});
