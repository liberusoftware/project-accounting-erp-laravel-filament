<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\JobEstimatesFilament\JobEstimatesFilamentPlugin;
use Liberu\Accounting\JobEstimatesFilament\JobEstimatesFilamentServiceProvider;
use Liberu\Accounting\JobEstimatesFilament\Resources\JobEstimateResource;
use Liberu\Accounting\JobEstimatesFilament\Resources\Pages\ListJobEstimates;

it('registers and configures accounting-job-estimates-filament filament resources', function (): void {
    $provider = $this->app->register(JobEstimatesFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(JobEstimatesFilamentServiceProvider::class);
    $plugin = JobEstimatesFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-job-estimates-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListJobEstimates();
    expect(JobEstimateResource::getPages())->toBeArray();
    expect(JobEstimateResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    $user = new User();
    $user->current_team_id = 1;
    $this->actingAs($user);
    expect(JobEstimateResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(JobEstimateResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(JobEstimateResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
