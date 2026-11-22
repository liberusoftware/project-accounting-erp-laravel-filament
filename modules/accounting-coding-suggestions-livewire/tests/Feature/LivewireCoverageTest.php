<?php

declare(strict_types=1);

use Liberu\Accounting\CodingSuggestionsLivewire\CodingSuggestionsLivewireServiceProvider;

it('boots accounting-coding-suggestions-livewire service provider', function (): void {
    $provider = $this->app->register(CodingSuggestionsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }

    expect($provider)->toBeInstanceOf(CodingSuggestionsLivewireServiceProvider::class);
});
