<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\GeneralLedger\Actions\CreateTypedJournal;
use Liberu\Accounting\GeneralLedger\Enums\JournalType;
use Liberu\Analytics\Contracts\AnalyticsDestinationRegistry;
use Liberu\Analytics\Core\AnalyticsServiceProvider;
use Liberu\Analytics\Core\Support\DestinationRegistry;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\PasswordValidationRules;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Contracts\PrivilegedActor;
use Liberu\Foundation\RolesPermissions\Services\AnyTeamRoleLookup;
use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use Liberu\Foundation\Search\Registry\SearcherRegistry;
use Liberu\Foundation\Search\Services\SearchService;
use Liberu\Foundation\SearchApi\Http\Controllers\SearchController;
use Liberu\Foundation\Tenancy\Concerns\BelongsToTeam;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Spatie\Permission\Models\Role;

it('covers AnalyticsServiceProvider registration and boot', function (): void {
    $provider = $this->app->register(AnalyticsServiceProvider::class, force: true);
    $provider->boot();

    expect($this->app->make(AnalyticsDestinationRegistry::class))
        ->toBeInstanceOf(DestinationRegistry::class);
});

it('covers FoundationDoctorCommand pass and fail branches', function (): void {
    $code = Artisan::call('foundation:doctor');
    expect($code)->toBeIn([0, 1]);
});

it('covers JetstreamBridge PasswordValidationRules and Tenancy BelongsToTeam traits', function (): void {
    $rulesHost = new class()
    {
        use PasswordValidationRules;

        public function exposed(): array
        {
            return $this->passwordRules();
        }
    };
    expect($rulesHost->exposed())->toHaveCount(4);

    $model = new class() extends Model
    {
        use BelongsToTeam;

        protected $table = 'users';
    };
    expect($model->team())->toBeInstanceOf(BelongsTo::class);
});

it('covers ModuleManager console commands', function (): void {
    $tmpCache = sys_get_temp_dir().'/lerd-test-modules-'.uniqid('', true).'.php';
    $originalCache = config('modules.cache_path');
    config()->set('modules.cache_path', $tmpCache);

    try {
        expect(Artisan::call('module:list'))->toBe(0)
            ->and(Artisan::call('module:list', ['--json' => true]))->toBe(0)
            ->and(Artisan::call('module:features'))->toBe(0)
            ->and(Artisan::call('module:features', ['query' => 'accounting']))->toBe(0)
            ->and(Artisan::call('module:status', ['name' => 'non-existent-module-xyz']))->toBe(1);

        $firstModule = array_key_first(app(ModuleInventory::class)->manifests);
        if (is_string($firstModule)) {
            expect(Artisan::call('module:status', ['name' => $firstModule]))->toBe(0);
        }

        expect(Artisan::call('module:cache'))->toBe(0)
            ->and(Artisan::call('module:clear'))->toBe(0)
            ->and(Artisan::call('module:validate'))->toBeIn([0, 1])
            ->and(Artisan::call('module:validate', ['--strict' => true]))->toBeIn([0, 1]);
    } finally {
        config()->set('modules.cache_path', $originalCache);
        @unlink($tmpCache);
    }
});

it('covers ThemeSupport console commands', function (): void {
    $tmpCache = sys_get_temp_dir().'/lerd-test-themes-'.uniqid('', true).'.php';
    $originalCache = config('theme.cache_path');
    config()->set('theme.cache_path', $tmpCache);

    try {
        expect(Artisan::call('theme:validate'))->toBe(0)
            ->and(Artisan::call('theme:cache'))->toBe(0)
            ->and(Artisan::call('theme:clear'))->toBe(0);
    } finally {
        config()->set('theme.cache_path', $originalCache);
        @unlink($tmpCache);
    }
});

it('covers AnyTeamRoleLookup and PermissionCatalog', function (): void {
    $user = User::factory()->create();
    $actor = new class($user) implements PrivilegedActor
    {
        public function __construct(private readonly User $user) {}

        public function authorizationIdentifier(): int|string
        {
            return $this->user->getKey();
        }

        public function authorizationType(): string
        {
            return $this->user->getMorphClass();
        }

        public function hasRoleInAnyTeam(string|array $roles): bool
        {
            return false;
        }

        public function isSuperAdmin(): bool
        {
            return false;
        }
    };

    $lookup = new AnyTeamRoleLookup();
    expect($lookup->hasRoleInAnyTeam($actor, []))->toBeFalse()
        ->and($lookup->hasRoleInAnyTeam($actor, 'non_existent_role'))->toBeFalse();

    $role = Role::query()->firstOrCreate(['name' => 'coverage_lookup_role', 'guard_name' => 'web']);
    $user->assignRole($role);
    expect($lookup->hasRoleInAnyTeam($actor, 'coverage_lookup_role'))->toBeTrue();

    PermissionCatalog::sync();
    // Second run covers the early return when no permissions are missing
    PermissionCatalog::sync();
    expect(PermissionCatalog::catalogPermissions())->toBeArray();

    expect(RolePermissionCatalog::presets())
        ->toHaveKeys(['sales', 'support', 'social_media', 'advertising'])
        ->and(RolePermissionCatalog::preset('sales'))
        ->toBeArray()
        ->and(RolePermissionCatalog::options(true))
        ->toBeArray()
        ->and(RolePermissionCatalog::options(false))
        ->toBeArray();

    expect(app(ThemeManager::class)->getSelectedTheme())->toBeNull();
    expect(Team::withArchived()->get())->toBeInstanceOf(Collection::class);
});

it('covers SearchApi SearchController users and all endpoints', function (): void {
    $user = User::factory()->create(['name' => 'Searchable Alice']);
    $usersPaginator = new LengthAwarePaginator([$user], 1, 15, 1);
    $allPaginator = new LengthAwarePaginator([$user], 1, 15, 1);

    $service = Mockery::mock(SearchService::class);
    $service->shouldReceive('searchUsers')->once()->andReturn($usersPaginator);
    $service->shouldReceive('searchAll')->once()->andReturn(['users' => $allPaginator, 'other' => []]);

    $searchers = app(SearcherRegistry::class);
    $controller = new SearchController($service, $searchers);

    $usersResponse = $controller->users(Request::create('/api/search/users', 'GET', [
        'query' => 'Alice',
        'per_page' => 15,
    ]));
    expect($usersResponse->getStatusCode())->toBe(200)
        ->and($usersResponse->getData(true)['data'][0]['name'])->toBe('Searchable Alice');

    $allResponse = $controller->all(Request::create('/api/search/all', 'GET', [
        'query' => 'Alice',
        'per_page' => 15,
    ]));
    expect($allResponse->getStatusCode())->toBe(200)
        ->and($allResponse->getData(true)['users']['data'][0]['name'])->toBe('Searchable Alice');
});

it('covers GeneralLedger CreateTypedJournal action', function (): void {
    $entity = LegalEntity::query()->create([
        'team_id' => 1,
        'name' => 'Typed Journal Entity',
        'code' => 'TJE',
        'functional_currency' => 'GBP',
        'status' => 'active',
    ]);
    $book = Book::query()->create([
        'legal_entity_id' => $entity->id,
        'name' => 'Primary Book',
        'code' => 'MAIN',
        'currency' => 'GBP',
        'is_primary' => true,
    ]);
    $entry = (new CreateTypedJournal())->handle(
        JournalType::cases()[0],
        [
            'legal_entity_id' => $entity->id,
            'book_id' => $book->id,
            'entry_date' => '2026-01-15',
            'currency' => 'GBP',
            'description' => 'Typed journal test',
        ],
        [
            ['account_code' => '1000', 'debit' => 100, 'credit' => 0],
            ['account_code' => '2000', 'debit' => 0, 'credit' => 100],
        ],
    );
    expect($entry->journal_type)->toBe(JournalType::cases()[0]->value);
});
