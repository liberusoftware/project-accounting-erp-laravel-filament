<?php

use App\Exceptions\Handler;
use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;

function withAccountingProductionPolicy(Closure $assertions): void
{
    $application = app();
    $environment = $application->environment();
    $lazyLoading = Model::preventsLazyLoading();
    $discardingAttributes = Model::preventsSilentlyDiscardingAttributes();
    $missingAttributes = Model::preventsAccessingMissingAttributes();

    try {
        $application['env'] = 'production';
        (new AppServiceProvider($application))->boot();
        $assertions();
    } finally {
        $application['env'] = $environment;
        URL::forceScheme(null);
        Model::preventLazyLoading($lazyLoading);
        Model::preventSilentlyDiscardingAttributes($discardingAttributes);
        Model::preventAccessingMissingAttributes($missingAttributes);
    }
}

it('requires strong passwords and rejects known compromised credentials', function (string $password, bool $notCompromised, bool $expected) {
    $verifier = Mockery::mock(UncompromisedVerifier::class);
    $verifier->shouldReceive('verify')->with(['value' => $password, 'threshold' => 0])->andReturn($notCompromised);
    app()->instance(UncompromisedVerifier::class, $verifier);

    withAccountingProductionPolicy(function () use ($password, $expected): void {
        expect(Validator::make(['password' => $password], ['password' => Password::defaults()])->passes())->toBe($expected);
    });
})->with([
    'too short' => ['Short1!', true, false],
    'missing uppercase' => ['lowercase-password-123!', true, false],
    'missing lowercase' => ['UPPERCASE-PASSWORD-123!', true, false],
    'missing digits' => ['A-compliant-password!', true, false],
    'missing symbols' => ['CompliantPassword1234', true, false],
    'compromised strong password' => ['A-compliant-password-123!', false, false],
    'uncompromised strong password' => ['A-compliant-password-123!', true, true],
]);

it('forces HTTPS in production while keeping mass assignment guarded', function () {
    withAccountingProductionPolicy(function (): void {
        expect(URL::to('/secure-check'))->toStartWith('https://')
            ->and(Model::isUnguarded())->toBeFalse()
            ->and(Model::preventsSilentlyDiscardingAttributes())->toBeFalse();
    });
});

it('reports exceptions through the configured logger', function () {
    $exception = new RuntimeException('host policy report');
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->once()->with(
        'host policy report', Mockery::on(fn (array $context): bool => ($context['exception'] ?? null) === $exception),
    );
    app()->instance(LoggerInterface::class, $logger);

    (new Handler($this->app))->report($exception);
});

it('keeps credentials out of validation redirect input', function () {
    app()->instance(ExceptionHandler::class, new Handler($this->app));
    Route::post('/_test/credentials', fn () => throw ValidationException::withMessages(['password' => 'Invalid password.']));

    $this->from('/profile')->post('/_test/credentials', [
        'name' => 'Alice',
        'current_password' => 'private-current',
        'password' => 'private-new',
        'password_confirmation' => 'private-new',
    ])->assertRedirect('/profile')
        ->assertSessionHasErrors('password')
        ->assertSessionHasInput('name', 'Alice')
        ->assertSessionMissing('_old_input.current_password')
        ->assertSessionMissing('_old_input.password')
        ->assertSessionMissing('_old_input.password_confirmation');
});

it('registers broadcast authentication and authorizes only the owning user channel', function () {
    (new BroadcastServiceProvider($this->app))->boot();
    $route = Route::getRoutes()->getByAction(BroadcastController::class.'@authenticate');
    $authorize = Broadcast::getChannels()['App.Models.User.{id}'];
    $user = new GenericUser(['id' => 1]);

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('broadcasting/auth')
        ->and($authorize($user, '1'))->toBeTrue()
        ->and($authorize($user, '2'))->toBeFalse();
});
