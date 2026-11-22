<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\JournalApprovalsFilament\JournalApprovalsFilamentPlugin;
use Liberu\Accounting\JournalApprovalsFilament\JournalApprovalsFilamentServiceProvider;
use Liberu\Accounting\JournalApprovalsFilament\Resources\JournalApprovalResource;
use Liberu\Accounting\JournalApprovalsFilament\Resources\Pages\ListJournalApprovals;

it('registers and configures accounting-journal-approvals-filament filament resources', function (): void {
    $provider = $this->app->register(JournalApprovalsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(JournalApprovalsFilamentServiceProvider::class);
    $plugin = JournalApprovalsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-journal-approvals-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListJournalApprovals;
    expect(JournalApprovalResource::getPages())->toBeArray();
    expect(JournalApprovalResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(JournalApprovalResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
