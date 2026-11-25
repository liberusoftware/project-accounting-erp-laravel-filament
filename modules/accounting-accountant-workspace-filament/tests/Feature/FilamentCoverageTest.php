<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\AccountantWorkspaceFilament\AccountantWorkspaceFilamentServiceProvider;
use Liberu\Accounting\AccountantWorkspaceFilament\Resources\Pages\CreateWorkspaceItem;
use Liberu\Accounting\AccountantWorkspaceFilament\Resources\Pages\ListWorkspaceItems;
use Liberu\Accounting\AccountantWorkspaceFilament\Resources\WorkspaceItemResource;

it('registers and configures accounting-accountant-workspace-filament filament resources', function (): void {
    $provider = $this->app->register(AccountantWorkspaceFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AccountantWorkspaceFilamentServiceProvider::class);
    $host = new ListWorkspaceItems();
    expect(WorkspaceItemResource::getPages())->toBeArray();
    expect(WorkspaceItemResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(WorkspaceItemResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(WorkspaceItemResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateWorkspaceItem(), CreateWorkspaceItem::class)();
    expect($mutated)->toBeArray();
});
