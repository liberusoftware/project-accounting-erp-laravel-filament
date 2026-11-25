<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\PoliciesFilament\AccountingPoliciesFilamentPlugin;
use Liberu\Accounting\PoliciesFilament\AccountingPoliciesFilamentServiceProvider;
use Liberu\Accounting\PoliciesFilament\Resources\PolicyRuleResource;
use Liberu\Accounting\PoliciesFilament\Resources\PolicyRuleResource\Pages\ListPolicyRules;

it('registers and configures accounting-policies-filament filament resources', function (): void {
    $provider = $this->app->register(AccountingPoliciesFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AccountingPoliciesFilamentServiceProvider::class);
    $plugin = AccountingPoliciesFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-policies-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListPolicyRules();
    expect(PolicyRuleResource::getPages())->toBeArray();
    expect(PolicyRuleResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(PolicyRuleResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
