<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Accounting\ReviewFilament\Resources\Pages\CreateReviewItem;
use Liberu\Accounting\ReviewFilament\Resources\Pages\ListReviewItems;
use Liberu\Accounting\ReviewFilament\Resources\ReviewItemResource;
use Liberu\Accounting\ReviewFilament\ReviewFilamentServiceProvider;

it('registers and configures accounting-review-filament filament resources', function (): void {
    $provider = $this->app->register(ReviewFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ReviewFilamentServiceProvider::class);
    $host = new ListReviewItems();
    expect(ReviewItemResource::getPages())->toBeArray();
    expect(ReviewItemResource::getEloquentQuery())->toBeInstanceOf(Builder::class);
    expect(ReviewItemResource::form(Schema::make($host)))->toBeInstanceOf(Schema::class);
    expect(ReviewItemResource::table(Table::make($host)))->toBeInstanceOf(Table::class);
    $mutated = Closure::bind(fn () => $this->mutateFormDataBeforeCreate(['name' => 'Test']), new CreateReviewItem(), CreateReviewItem::class)();
    expect($mutated)->toBeArray();
});
