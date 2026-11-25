<?php

declare(strict_types=1);

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Foundation\OrganizationsFilament\OrganizationsFilamentPlugin;
use Liberu\Foundation\OrganizationsFilament\OrganizationsFilamentServiceProvider;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\CreateTeam;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\EditTeam;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\ListTeams;

it('covers OrganizationsFilamentPlugin, service provider, TeamResource form/table/pages, and page header actions', function (): void {
    $provider = $this->app->register(OrganizationsFilamentServiceProvider::class, force: true);
    expect($provider)->toBeInstanceOf(OrganizationsFilamentServiceProvider::class);

    $plugin = OrganizationsFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-organizations');

    $panel = Panel::make()->id('test-org-panel');
    $plugin->register($panel);
    $plugin->boot($panel);

    expect($panel->getResources())->toContain(TeamResource::class)
        ->and(TeamResource::isScopedToTenant())->toBeFalse()
        ->and(array_keys(TeamResource::getPages()))->toBe(['index', 'create', 'edit']);

    $listPage = new ListTeams();
    $editPage = new EditTeam();
    $createPage = new CreateTeam();

    expect($createPage)->toBeInstanceOf(CreateTeam::class);

    $schema = TeamResource::form(Schema::make($listPage));
    expect($schema->getComponents())->toHaveCount(3);

    $table = TeamResource::table(Table::make($listPage));
    expect($table->getColumns())->toHaveCount(4);

    $listHeaderActions = Closure::bind(fn (): array => $this->getHeaderActions(), $listPage, ListTeams::class)();
    expect($listHeaderActions)->toHaveCount(1)
        ->and($listHeaderActions[0])->toBeInstanceOf(CreateAction::class);

    $editHeaderActions = Closure::bind(fn (): array => $this->getHeaderActions(), $editPage, EditTeam::class)();
    expect($editHeaderActions)->toHaveCount(1)
        ->and($editHeaderActions[0])->toBeInstanceOf(DeleteAction::class);
});
