<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Liberu\Foundation\ApiAccess\ApiAccessServiceProvider;
use Liberu\Foundation\ApiAccess\Http\Middleware\ApiContract;
use Liberu\Foundation\ApiAccess\Http\Middleware\Idempotency;
use Liberu\Foundation\ApiAccess\Http\Middleware\ReplayIdempotentRequest;
use Liberu\Foundation\ApiAccess\Support\IdempotencyStore;
use Liberu\Foundation\ApiAccess\Support\TokenPolicy;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function apiAccessTestRequest(string $method = 'POST', string $body = 'request', array $headers = [], ?Model $model = null): Request
{
    $request = Request::create('/api/v1/records/1', $method, [], [], [], [], $body);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    if ($model !== null) {
        $route = new Route($method, 'api/v1/records/{record}', static fn () => null);
        $route->bind($request);
        $route->setParameter('record', $model);
        $request->setRouteResolver(static fn () => $route);
    }

    return $request;
}

function apiAccessTestModel(?string $updatedAt = '2026-01-01 12:00:00'): Model
{
    $model = new class() extends Model
    {
        protected $casts = ['updated_at' => 'datetime'];
    };

    return $model->forceFill(['id' => 1, 'updated_at' => $updatedAt]);
}

it('registers config, middleware alias, and api rate limiter in service provider', function (): void {
    $provider = $this->app->register(ApiAccessServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(ApiAccessServiceProvider::class)
        ->and(config('api-access.maximum_token_days'))->toBe(90)
        ->and(config('api-access.idempotency_hours'))->toBe(24)
        ->and($this->app['router']->getMiddleware()['api.idempotency'] ?? null)->toBe(Idempotency::class);

    $limiter = RateLimiter::limiter('api');
    expect($limiter)->not->toBeNull();

    $guestRequest = Request::create('/api/v1/ping', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.5']);
    $guestLimit = $limiter($guestRequest);
    expect($guestLimit)->toBeInstanceOf(Limit::class)
        ->and($guestLimit->key)->toBe('10.0.0.5')
        ->and($guestLimit->maxAttempts)->toBe(60);

    config(['api-access.rate_limit' => '120,2']);
    $userRequest = Request::create('/api/v1/ping', 'GET');
    $userRequest->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => 42]));
    $userLimit = $limiter($userRequest);
    expect($userLimit)->toBeInstanceOf(Limit::class)
        ->and($userLimit->key)->toBe(42)
        ->and($userLimit->maxAttempts)->toBe(120)
        ->and($userLimit->decaySeconds)->toBe(120);
});

it('enforces token scopes and expiry bounds in TokenPolicy', function (): void {
    $policy = new TokenPolicy();

    expect($policy->scopes(['read', 'write', 'read'], ['read', 'write', 'delete']))
        ->toBe(['read', 'write']);

    expect(fn () => $policy->scopes(['read', 'admin'], ['read', 'write']))
        ->toThrow(InvalidArgumentException::class, 'Requested token scope is not permitted.');

    config(['api-access.maximum_token_days' => 30]);

    $defaultExpiry = $policy->expiresAt();
    $withinLimit = new DateTimeImmutable('+5 days');
    $overLimit = new DateTimeImmutable('+60 days');

    expect($defaultExpiry->getTimestamp())->toBeGreaterThan((new DateTimeImmutable('+29 days'))->getTimestamp())
        ->and($policy->expiresAt($withinLimit))->toBe($withinLimit)
        ->and($policy->expiresAt($overLimit)->getTimestamp())->toBeLessThan($overLimit->getTimestamp());
});

it('manages idempotency lifecycle including expiry reuse, conflicts, and race conditions', function (): void {
    $store = new IdempotencyStore();

    expect($store->begin('user:1', 'key-1', '{"a":1}'))->toBeNull();

    $store->complete('user:1', 'key-1', 201, '{"id":10}');

    $replayed = $store->begin('user:1', 'key-1', '{"a":1}');
    expect($replayed)->not->toBeNull()
        ->and((int) $replayed->response_status)->toBe(201)
        ->and($replayed->response_body)->toBe('{"id":10}');

    expect(fn () => $store->begin('user:1', 'key-1', '{"a":2}'))
        ->toThrow(RuntimeException::class, 'Idempotency key was reused with a different request.');

    // Expired entry is overwritten in place and treated as a new reservation
    DB::table('api_idempotency_keys')
        ->where('identity_ref', 'user:1')
        ->where('key', 'key-1')
        ->update(['expires_at' => now()->subHour()]);

    expect($store->begin('user:1', 'key-1', '{"a":2}'))->toBeNull();
    $row = DB::table('api_idempotency_keys')->where('identity_ref', 'user:1')->where('key', 'key-1')->first();
    expect($row->response_status)->toBeNull()
        ->and($row->response_body)->toBeNull();

    $store->forget('user:1', 'key-1');
    $this->assertDatabaseCount('api_idempotency_keys', 0);

    // Cover concurrent insertOrIgnore === 0 race paths deterministically
    $originalDb = DB::getFacadeRoot();
    $makeFakeDb = static function (?object $createdRow): object {
        return new class($createdRow)
        {
            private int $firstCallCount = 0;

            public function __construct(private readonly ?object $createdRow) {}

            public function transaction(Closure $callback): mixed
            {
                return $callback();
            }

            public function table(string $table): object
            {
                return $this;
            }

            public function where(string $column, mixed $value): object
            {
                return $this;
            }

            public function lockForUpdate(): object
            {
                return $this;
            }

            public function first(): ?object
            {
                $this->firstCallCount++;

                return $this->firstCallCount === 1 ? null : $this->createdRow;
            }

            public function insertOrIgnore(array $attributes): int
            {
                return 0;
            }
        };
    };

    try {
        $expectedHash = hash('sha256', 'race-body');

        DB::swap($makeFakeDb((object) ['request_hash' => $expectedHash, 'response_status' => 200, 'response_body' => '{}']));
        $found = $store->begin('user:race', 'race-1', 'race-body');
        expect($found)->not->toBeNull()->and($found->response_body)->toBe('{}');

        DB::swap($makeFakeDb((object) ['request_hash' => hash('sha256', 'other-body'), 'response_status' => 200, 'response_body' => '{}']));
        expect(fn () => $store->begin('user:race', 'race-2', 'race-body'))
            ->toThrow(RuntimeException::class, 'Idempotency key was reused with a different request.');

        DB::swap($makeFakeDb(null));
        expect(fn () => $store->begin('user:race', 'race-3', 'race-body'))
            ->toThrow(RuntimeException::class, 'Idempotency request could not be reserved.');
    } finally {
        DB::swap($originalDb);
    }
});

it('handles Idempotency middleware for reads, writes, replays, in-progress, failures, and exceptions', function (): void {
    $middleware = new Idempotency(new IdempotencyStore());

    // Bypasses reads and missing keys
    foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
        $resp = $middleware->handle(apiAccessTestRequest($method, '', ['Idempotency-Key' => 'k-read']), fn () => response('ok', 200));
        expect($resp->getStatusCode())->toBe(200);
    }
    $noKey = $middleware->handle(apiAccessTestRequest('POST', 'body'), fn () => response('created', 201));
    expect($noKey->getStatusCode())->toBe(201);
    $this->assertDatabaseCount('api_idempotency_keys', 0);

    // Stores and replays authenticated write
    $actor = new GenericUser(['id' => 7, 'current_team_id' => 99]);
    $req = apiAccessTestRequest('POST', '{"amount":50}', ['Idempotency-Key' => 'idem-1']);
    $req->setUserResolver(static fn (): GenericUser => $actor);

    $first = $middleware->handle($req, fn () => response()->json(['saved' => true], 201));
    expect($first->getStatusCode())->toBe(201)
        ->and($first->headers->get('Idempotency-Key'))->toBe('idem-1');

    $replay = $middleware->handle($req, fn () => throw new LogicException('Must not re-run'));
    expect($replay->getStatusCode())->toBe(201)
        ->and($replay->getContent())->toBe($first->getContent())
        ->and($replay->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($replay->headers->get('Content-Type'))->toBe('application/json');

    // In-progress returns 409
    DB::table('api_idempotency_keys')->update(['response_body' => null, 'response_status' => null]);
    $inProgress = $middleware->handle($req, fn () => throw new LogicException('Must not re-run'));
    expect($inProgress->getStatusCode())->toBe(409)
        ->and(json_decode((string) $inProgress->getContent(), true)['message'])->toBe('This request is already in progress.');

    // 5xx and StreamedResponse forget the key
    $errReq = apiAccessTestRequest('POST', 'x', ['Idempotency-Key' => 'idem-err']);
    $middleware->handle($errReq, fn () => response('boom', 500));
    expect(DB::table('api_idempotency_keys')->where('key', 'idem-err')->exists())->toBeFalse();

    $streamReq = apiAccessTestRequest('POST', 'x', ['Idempotency-Key' => 'idem-stream']);
    $middleware->handle($streamReq, fn () => new StreamedResponse(static fn () => null));
    expect(DB::table('api_idempotency_keys')->where('key', 'idem-stream')->exists())->toBeFalse();

    // Exception forgets key and rethrows
    $throwReq = apiAccessTestRequest('POST', 'x', ['Idempotency-Key' => 'idem-throw']);
    expect(fn () => $middleware->handle($throwReq, fn () => throw new RuntimeException('kaboom')))
        ->toThrow(RuntimeException::class, 'kaboom');
    expect(DB::table('api_idempotency_keys')->where('key', 'idem-throw')->exists())->toBeFalse();
});

it('handles ReplayIdempotentRequest middleware across all branches', function (): void {
    $middleware = new ReplayIdempotentRequest(new IdempotencyStore());

    // Empty key passes through
    $pass = $middleware->handle(apiAccessTestRequest('POST', 'body'), fn () => response('ok', 200));
    expect($pass->getStatusCode())->toBe(200);
    $this->assertDatabaseCount('api_idempotency_keys', 0);

    // Guest successful write is stored and replayed
    $req = apiAccessTestRequest('POST', '{"x":1}', ['Idempotency-Key' => 'replay-1']);
    $first = $middleware->handle($req, fn () => response()->json(['ok' => true], 201));
    expect($first->getStatusCode())->toBe(201);

    $replayed = $middleware->handle($req, fn () => throw new LogicException('Must not re-run'));
    expect($replayed->getStatusCode())->toBe(201)
        ->and($replayed->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($replayed->headers->get('Content-Type'))->toBe('application/json');

    // In-progress returns 409 with Retry-After: 1
    DB::table('api_idempotency_keys')->update(['response_body' => null]);
    $inProgress = $middleware->handle($req, fn () => throw new LogicException('Must not re-run'));
    expect($inProgress->getStatusCode())->toBe(409)
        ->and($inProgress->headers->get('Retry-After'))->toBe('1');

    // Non-successful response forgets key
    $failReq = apiAccessTestRequest('POST', 'bad', ['Idempotency-Key' => 'replay-422']);
    $failReq->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => 3, 'current_team_id' => 8]));
    $failResp = $middleware->handle($failReq, fn () => response()->json(['error' => true], 422));
    expect($failResp->getStatusCode())->toBe(422)
        ->and(DB::table('api_idempotency_keys')->where('key', 'replay-422')->exists())->toBeFalse();

    // StreamedResponse forgets key
    $streamReq = apiAccessTestRequest('POST', 'stream', ['Idempotency-Key' => 'replay-stream']);
    $middleware->handle($streamReq, fn () => new StreamedResponse(static fn () => null));
    expect(DB::table('api_idempotency_keys')->where('key', 'replay-stream')->exists())->toBeFalse();

    // Exception forgets key and rethrows
    $exReq = apiAccessTestRequest('POST', 'err', ['Idempotency-Key' => 'replay-ex']);
    expect(fn () => $middleware->handle($exReq, fn () => throw new RuntimeException('downstream')))
        ->toThrow(RuntimeException::class, 'downstream');
    expect(DB::table('api_idempotency_keys')->where('key', 'replay-ex')->exists())->toBeFalse();
});

it('handles ApiContract middleware ETags, conditional requests, idempotency, and problem responses', function (): void {
    $middleware = new ApiContract(new IdempotencyStore());

    // Content ETag & If-None-Match
    $body = '{"id":1}';
    $etag = '"'.hash('sha256', $body).'"';

    $r200 = $middleware->handle(apiAccessTestRequest('GET'), fn () => response($body, 200));
    expect($r200->getStatusCode())->toBe(200)->and($r200->headers->get('ETag'))->toBe($etag);

    $r304List = $middleware->handle(apiAccessTestRequest('GET', '', ['If-None-Match' => '"old", '.$etag]), fn () => response($body, 200));
    expect($r304List->getStatusCode())->toBe(304)->and($r304List->getContent())->toBe('');

    $r304Wild = $middleware->handle(apiAccessTestRequest('GET', '', ['If-None-Match' => '*']), fn () => response($body, 200));
    expect($r304Wild->getStatusCode())->toBe(304);

    $rMismatch = $middleware->handle(apiAccessTestRequest('GET', '', ['If-None-Match' => '"other"']), fn () => response($body, 200));
    expect($rMismatch->getStatusCode())->toBe(200);

    // Non-cacheable responses have no ETag
    foreach ([
        fn () => response('', 204),
        fn () => response('missing', 404),
        fn () => response('', 200),
        fn () => new StreamedResponse(static fn () => null),
    ] as $factory) {
        expect($middleware->handle(apiAccessTestRequest('GET'), $factory)->headers->has('ETag'))->toBeFalse();
    }

    // GET ignores Idempotency-Key; write without key works
    $middleware->handle(apiAccessTestRequest('GET', '', ['Idempotency-Key' => 'invalid key']), fn () => response()->json(['ok' => true]));
    $middleware->handle(apiAccessTestRequest('POST', 'body'), fn () => response()->json(['ok' => true], 201));
    $this->assertDatabaseCount('api_idempotency_keys', 0);

    // Malformed Idempotency-Key aborts 422
    foreach (['not valid', '', str_repeat('x', 129)] as $badKey) {
        expect(fn () => $middleware->handle(
            apiAccessTestRequest('POST', 'body', ['Idempotency-Key' => $badKey]),
            fn () => throw new LogicException('Must not run'),
        ))->toThrow(HttpException::class, 'A valid Idempotency-Key header is required.');
    }

    // Replay across POST, PUT, PATCH, DELETE
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $headers = ['Idempotency-Key' => 'key-'.$method];
        $first = $middleware->handle(apiAccessTestRequest($method, 'payload', $headers), fn () => response()->json(['m' => $method], 201));
        $replay = $middleware->handle(apiAccessTestRequest($method, 'payload', $headers), fn () => throw new LogicException('Must not re-run'));
        expect($replay->getStatusCode())->toBe(201)
            ->and($replay->getContent())->toBe($first->getContent())
            ->and($replay->headers->get('Idempotency-Replayed'))->toBe('true');
    }

    // Conflict (different payload) & in-progress -> 409 problem+json
    $conflict = $middleware->handle(
        apiAccessTestRequest('POST', 'different-payload', ['Idempotency-Key' => 'key-POST']),
        fn () => throw new LogicException('Must not run'),
    );
    expect($conflict->getStatusCode())->toBe(409)
        ->and($conflict->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and(json_decode((string) $conflict->getContent(), true)['type'])->toBe('https://liberu.software/problems/idempotency-conflict');

    DB::table('api_idempotency_keys')->where('key', 'key-POST')->update(['response_status' => null, 'response_body' => null]);
    $inProgress = $middleware->handle(
        apiAccessTestRequest('POST', 'payload', ['Idempotency-Key' => 'key-POST']),
        fn () => throw new LogicException('Must not run'),
    );
    expect($inProgress->getStatusCode())->toBe(409)
        ->and(json_decode((string) $inProgress->getContent(), true)['type'])->toBe('https://liberu.software/problems/idempotency-in-progress');

    // Actor & currentTeam identity isolation
    $actor = new GenericUser(['id' => 10]);
    $actor->currentTeam = apiAccessTestModel()->forceFill(['id' => 5]);
    $actorReq = apiAccessTestRequest('POST', 'payload', ['Idempotency-Key' => 'key-actor']);
    $actorReq->setUserResolver(static fn (): GenericUser => $actor);
    $actorResp = $middleware->handle($actorReq, fn () => response()->json(['ok' => true], 201));
    expect($actorResp->getStatusCode())->toBe(201);

    // 5xx, StreamedResponse, and downstream exceptions release idempotency key
    $middleware->handle(apiAccessTestRequest('POST', 'x', ['Idempotency-Key' => 'k-503']), fn () => response('err', 503));
    $middleware->handle(apiAccessTestRequest('POST', 'x', ['Idempotency-Key' => 'k-stream']), fn () => new StreamedResponse(static fn () => null));
    expect(DB::table('api_idempotency_keys')->whereIn('key', ['k-503', 'k-stream'])->count())->toBe(0);

    expect(fn () => $middleware->handle(apiAccessTestRequest('POST', 'x', ['Idempotency-Key' => 'k-ex']), fn () => throw new RuntimeException('fail')))
        ->toThrow(RuntimeException::class, 'fail');
    expect(fn () => $middleware->handle(apiAccessTestRequest('POST', 'x'), fn () => throw new RuntimeException('fail2')))
        ->toThrow(RuntimeException::class, 'fail2');
    expect(DB::table('api_idempotency_keys')->where('key', 'k-ex')->exists())->toBeFalse();

    // Model ETag & If-Match (timestamped and null-timestamp)
    foreach (['2026-01-01 12:00:00', null] as $ts) {
        $model = apiAccessTestModel($ts);
        $read = $middleware->handle(apiAccessTestRequest('GET', '', [], $model), fn () => response()->json(['id' => 1]));
        $modelEtag = $read->headers->get('ETag');

        $okPatch = $middleware->handle(
            apiAccessTestRequest('PATCH', 'u', ['If-Match' => $modelEtag], $model),
            fn () => response()->json(['updated' => true]),
        );
        expect($okPatch->getStatusCode())->toBe(200)->and($okPatch->headers->get('ETag'))->toBe($modelEtag);

        $model->forceFill(['updated_at' => '2026-06-01 00:00:00']);
        $stalePatch = $middleware->handle(
            apiAccessTestRequest('PATCH', 'u', ['If-Match' => $modelEtag, 'Idempotency-Key' => 'k-stale'], $model),
            fn () => throw new LogicException('Must not run'),
        );
        expect($stalePatch->getStatusCode())->toBe(412)
            ->and(json_decode((string) $stalePatch->getContent(), true)['type'])->toBe('https://liberu.software/problems/precondition-failed')
            ->and(DB::table('api_idempotency_keys')->where('key', 'k-stale')->exists())->toBeFalse();
    }

    // If-Match without bound model -> 412
    $unboundReq = apiAccessTestRequest('PATCH', 'u', ['If-Match' => '*']);
    $unboundRoute = new Route('PATCH', 'api/v1/records/{record}', static fn () => null);
    $unboundRoute->bind($unboundReq);
    $unboundReq->setRouteResolver(static fn () => $unboundRoute);
    expect($middleware->handle($unboundReq, fn () => throw new LogicException('Must not run'))->getStatusCode())->toBe(412);
});
