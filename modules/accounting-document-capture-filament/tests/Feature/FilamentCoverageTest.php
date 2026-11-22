<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Liberu\Accounting\DocumentCaptureFilament\DocumentCaptureFilamentPlugin;
use Liberu\Accounting\DocumentCaptureFilament\DocumentCaptureFilamentServiceProvider;
use Liberu\Accounting\DocumentCaptureFilament\Resources\CapturedDocumentResource;
use Liberu\Accounting\DocumentCaptureFilament\Resources\Pages\ListCapturedDocuments;

it('registers and configures accounting-document-capture-filament filament resources', function (): void {
    $provider = $this->app->register(DocumentCaptureFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DocumentCaptureFilamentServiceProvider::class);
    $plugin = DocumentCaptureFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-document-capture-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
    $host = new ListCapturedDocuments();
    expect(CapturedDocumentResource::getPages())->toBeArray();
    expect(CapturedDocumentResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(CapturedDocumentResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
});
