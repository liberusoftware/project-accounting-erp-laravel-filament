<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ProjectsAndJobsFilament\ProjectsAndJobsFilamentPlugin;
use Liberu\Accounting\ProjectsAndJobsFilament\ProjectsAndJobsFilamentServiceProvider;
use Liberu\Accounting\ProjectsAndJobsFilament\Resources\Pages\ListProjectJobs;
use Liberu\Accounting\ProjectsAndJobsFilament\Resources\ProjectJobResource;

it('registers and configures accounting-projects-and-jobs-filament filament resources', function (): void {
    $provider = $this->app->register(ProjectsAndJobsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProjectsAndJobsFilamentServiceProvider::class);
    $plugin = ProjectsAndJobsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-projects-and-jobs-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListProjectJobs();
    expect(ProjectJobResource::getPages())->toBeArray();
    expect(ProjectJobResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(ProjectJobResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ProjectJobResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
