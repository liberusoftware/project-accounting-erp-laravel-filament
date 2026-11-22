<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\MatchingIntelligenceFilament\MatchingIntelligenceFilamentPlugin;
use Liberu\Accounting\MatchingIntelligenceFilament\MatchingIntelligenceFilamentServiceProvider;
use Liberu\Accounting\MatchingIntelligenceFilament\Resources\MatchingSuggestionResource;
use Liberu\Accounting\MatchingIntelligenceFilament\Resources\Pages\ListMatchingSuggestions;

it('registers and configures accounting-matching-intelligence-filament filament resources', function (): void {
    $provider = $this->app->register(MatchingIntelligenceFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(MatchingIntelligenceFilamentServiceProvider::class);
    $plugin = MatchingIntelligenceFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-matching-intelligence-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListMatchingSuggestions();
    expect(MatchingSuggestionResource::getPages())->toBeArray();
    expect(MatchingSuggestionResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(MatchingSuggestionResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
