<?php

declare(strict_types=1);

use App\Exceptions\AccountingIntegrationException;
use App\Models\ConnectedAccount as AppConnectedAccount;
use App\Models\Email;
use App\Models\User as AppUser;
use App\Services\Marketing\PlatformConfiguration;
use App\Services\Marketing\WorkspaceAccess;
use Carbon\Carbon;
use Composer\InstalledVersions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ViewFinderInterface;
use JoelButcher\Socialstream\Contracts\AuthenticatesOAuthCallback;
use JoelButcher\Socialstream\Events\NewOAuthRegistration;
use JoelButcher\Socialstream\Features;
use JoelButcher\Socialstream\RefreshedCredentials;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Liberu\Accounting\Erp\Models\User as ErpUser;
use Liberu\Analytics\Contracts\AnalyticsDestination;
use Liberu\Analytics\Contracts\AnalyticsDestinationRegistry;
use Liberu\Analytics\Contracts\AnalyticsEvent;
use Liberu\Analytics\Google\AnalyticsGoogleServiceProvider;
use Liberu\Analytics\Google\Contracts\GoogleTransport;
use Liberu\Analytics\Google\Support\GoogleDestination;
use Liberu\Analytics\Google\Support\GoogleEventMapper;
use Liberu\Analytics\Meta\AnalyticsMetaServiceProvider;
use Liberu\Analytics\Meta\Contracts\MetaTransport;
use Liberu\Analytics\Meta\Support\MetaCustomerNormalizer;
use Liberu\Analytics\Meta\Support\MetaDestination;
use Liberu\Foundation\Identity\Events\IdentityEvent;
use Liberu\Foundation\Identity\Jobs\SyncNewUserToCrm;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateUserFromProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\GenerateRedirectForProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\HandleInvalidState;
use Liberu\Foundation\Identity\Socialstream\Actions\ResolveSocialiteUser;
use Liberu\Foundation\Identity\Socialstream\Actions\SetUserPassword;
use Liberu\Foundation\Identity\Socialstream\Actions\UpdateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\VerifyTelegramAuthPayload;
use Liberu\Foundation\Identity\Socialstream\Contracts\ConnectedAccountOwner;
use Liberu\Foundation\Identity\Socialstream\Http\Controllers\TelegramAuthController;
use Liberu\Foundation\Identity\Socialstream\Http\Responses\SpaAwareOAuthResponse;
use Liberu\Foundation\Identity\Socialstream\Listeners\SyncNewOAuthUserToCrm;
use Liberu\Foundation\Identity\Socialstream\Models\ConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Policies\ConnectedAccountPolicy;
use Liberu\Foundation\Identity\Socialstream\Providers\SocialstreamServiceProvider;
use Liberu\Foundation\Identity\Support\ConfiguredRegistrationPolicy;
use Liberu\Foundation\Identity\Support\IdentifierNormalizer;
use Liberu\Foundation\Identity\Support\RejectingInvitationValidator;
use Liberu\Foundation\IdentityFilament\IdentityFilamentPlugin;
use Liberu\Foundation\IdentityFilament\IdentityFilamentServiceProvider;
use Liberu\Foundation\IdentityFilament\Resources\UserResource;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\CreateUser;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\EditUser;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\ListUsers;
use Liberu\Foundation\Integrations\Contracts\IntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\OAuthIntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\PaymentProviderAdapter;
use Liberu\Foundation\Integrations\Enums\SocialPlatform;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\CreateOAuthConfiguration;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\EditOAuthConfiguration;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\ListOAuthConfigurations;
use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource;
use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource\Pages\ListWebhookDeliveries;
use Liberu\Foundation\Integrations\Http\Controllers\AccountingIntegrationController;
use Liberu\Foundation\Integrations\Http\Controllers\Api\WebhookController;
use Liberu\Foundation\Integrations\Http\Controllers\OAuthConfigurationController;
use Liberu\Foundation\Integrations\Http\Controllers\OAuthController;
use Liberu\Foundation\Integrations\IntegrationsServiceProvider;
use Liberu\Foundation\Integrations\Models\AccountingIntegration;
use Liberu\Foundation\Integrations\Models\IntegrationConnection;
use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Models\WebhookDelivery as IntegrationWebhookDelivery;
use Liberu\Foundation\Integrations\Services\AccountingService;
use Liberu\Foundation\Integrations\Services\CalendarService;
use Liberu\Foundation\Integrations\Services\FacebookMessengerService;
use Liberu\Foundation\Integrations\Services\MessageService;
use Liberu\Foundation\Integrations\Services\OAuth\OAuthManager;
use Liberu\Foundation\Integrations\Services\QuickBooksService;
use Liberu\Foundation\Integrations\Services\Social\DirectSocialApiClient;
use Liberu\Foundation\Integrations\Services\WebhookService;
use Liberu\Foundation\Integrations\Services\WhatsAppBusinessService;
use Liberu\Foundation\Integrations\Services\XeroService;
use Liberu\Foundation\Integrations\Support\CredentialVault;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Foundation\Integrations\Support\SsrfGuard;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\CreateNewUser;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\ResetUserPassword;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\UpdateUserPassword;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\UpdateUserProfileInformation;
use Liberu\Foundation\JetstreamBridge\Actions\Jetstream\DeleteUser;
use Liberu\Foundation\JetstreamBridge\Providers\FortifyServiceProvider;
use Liberu\Foundation\LocalizationLivewire\Livewire\LanguageSwitcher;
use Liberu\Foundation\LocalizationLivewire\LocalizationLivewireServiceProvider;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidator;
use Liberu\Foundation\Observability\Contracts\Metrics;
use Liberu\Foundation\Observability\Contracts\ObservabilityActor;
use Liberu\Foundation\Observability\Http\Middleware\CorrelationId;
use Liberu\Foundation\Observability\ObservabilityServiceProvider;
use Liberu\Foundation\Observability\Providers\HorizonDashboardServiceProvider;
use Liberu\Foundation\Observability\Providers\TelescopeDashboardServiceProvider;
use Liberu\Foundation\Observability\Support\NullMetrics;
use Liberu\Foundation\Observability\Support\Redactor;
use Liberu\Foundation\Observability\Support\SloRegistry;
use Liberu\Foundation\Organizations\Models\Team as OrgTeam;
use Liberu\Foundation\RolesPermissions\Contracts\PrivilegedActor;
use Liberu\Foundation\RolesPermissions\Enums\Role as RoleEnum;
use Liberu\Foundation\RolesPermissions\Models\Permission as CustomPermissionModel;
use Liberu\Foundation\RolesPermissions\Models\Role as CustomRoleModel;
use Liberu\Foundation\RolesPermissions\Policies\RolePolicy;
use Liberu\Foundation\RolesPermissions\Registry\PermissionRegistry;
use Liberu\Foundation\RolesPermissions\RolesPermissionsServiceProvider;
use Liberu\Foundation\RolesPermissions\Services\AnyTeamRoleLookup;
use Liberu\Foundation\RolesPermissions\Services\BreakGlass;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissions\Services\SeparationOfDuty;
use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use Liberu\Foundation\RolesPermissionsFilament\Forms\RolePermissionBuilder;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\CreateRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\EditRole;
use Liberu\Foundation\RolesPermissionsFilament\Resources\RoleResource\Pages\ListRoles;
use Liberu\Foundation\RolesPermissionsFilament\RolesPermissionsFilamentPlugin;
use Liberu\Foundation\RolesPermissionsFilament\RolesPermissionsFilamentServiceProvider;
use Liberu\Foundation\Search\Concerns\Searchable;
use Liberu\Foundation\Search\Contracts\SearchIndexer;
use Liberu\Foundation\Search\Registry\IndexableRegistry;
use Liberu\Foundation\Search\Registry\SearcherRegistry;
use Liberu\Foundation\Search\SearchServiceProvider;
use Liberu\Foundation\Search\Services\LocalSearchIndexer;
use Liberu\Foundation\Search\Services\SearchService;
use Liberu\Foundation\Settings\Settings\SiteSettings;
use Liberu\Foundation\Theme\Cache\ThemeCache;
use Liberu\Foundation\Theme\Discovery\ThemeDiscovery;
use Liberu\Foundation\Theme\Exceptions\InvalidTheme;
use Liberu\Foundation\Theme\Manifests\ThemeManifest;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Liberu\Foundation\ThemeSupportLivewire\Livewire\ThemeSwitcher;
use Liberu\Foundation\ThemeSupportLivewire\ThemeSupportLivewireServiceProvider;
use Liberu\Foundation\Webhooks\Actions\DeliverWebhook;
use Liberu\Foundation\Webhooks\Actions\QueueWebhook;
use Liberu\Foundation\Webhooks\Actions\RegisterEndpoint;
use Liberu\Foundation\Webhooks\Actions\RotateEndpointSecret;
use Liberu\Foundation\Webhooks\Models\WebhookDelivery as FoundationWebhookDelivery;
use Liberu\Foundation\Webhooks\Models\WebhookEndpoint;
use Liberu\Foundation\Webhooks\Support\RetrySchedule;
use Liberu\Foundation\Webhooks\Support\SigningSecretVault;
use Liberu\Foundation\Webhooks\Support\WebhookSigner;
use Liberu\Foundation\Webhooks\Support\WebhookUrlPolicy;
use Liberu\Foundation\Webhooks\WebhooksServiceProvider;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once dirname(__DIR__, 2).'/modules/integrations/tests/Stubs.php';

function foundationMakeManifest(array $overrides = [], ?string $package = null): Manifest
{
    $dir = sys_get_temp_dir().'/host-foundation-mod-'.bin2hex(random_bytes(5));
    mkdir($dir, 0777, true);
    $data = array_replace([
        'name' => 'example',
        'display_name' => 'Example',
        'description' => 'Test',
        'version' => '1.0.0',
        'category' => 'capability',
        'provider' => ServiceProvider::class,
        'requires' => ['packages' => [], 'capabilities' => []],
        'capabilities' => [],
        'features' => ['Example feature'],
        'default_enabled' => true,
    ], $overrides);
    file_put_contents($dir.'/module.json', json_encode($data, JSON_THROW_ON_ERROR));
    if ($package !== null) {
        file_put_contents($dir.'/composer.json', json_encode(['name' => $package], JSON_THROW_ON_ERROR));
    }

    return Manifest::fromFile($dir.'/module.json');
}

function foundationWriteTheme(string $dir, array $manifestOverrides = [], ?array $composerOverrides = []): string
{
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $name = $manifestOverrides['name'] ?? basename($dir);
    $manifest = array_replace([
        'name' => $name,
        'display_name' => ucfirst($name),
        'description' => 'Test theme',
        'version' => '1.0.0',
        'provider' => WebhooksServiceProvider::class,
        'type' => 'shared',
        'parent' => null,
        'optimized_for' => ['accounting'],
        'tested_with' => ['accounting'],
        'required_capabilities' => [],
        'optional_capabilities' => [],
        'supports' => [],
        'assets' => ['css' => [], 'js' => []],
        'colors' => ['primary' => 'amber'],
    ], $manifestOverrides);

    $manifestPath = $dir.'/theme.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

    if ($composerOverrides !== null) {
        $composer = array_replace([
            'name' => 'liberusoftware/theme-'.$name,
            'type' => 'liberu-theme',
            'extra' => ['liberu' => ['name' => $name]],
        ], $composerOverrides);
        file_put_contents($dir.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    }

    return $manifestPath;
}

function foundationMakePrivilegedActor(bool $superAdmin): Authenticatable
{
    return new class($superAdmin) extends Authenticatable implements PrivilegedActor
    {
        public function __construct(private readonly bool $superAdmin = false)
        {
            parent::__construct();
        }

        public function authorizationIdentifier(): int|string
        {
            return 1;
        }

        public function authorizationType(): string
        {
            return 'test-actor';
        }

        public function hasRoleInAnyTeam(string|array $roles): bool
        {
            return $this->superAdmin;
        }

        public function isSuperAdmin(): bool
        {
            return $this->superAdmin;
        }

        public function hasRole(): bool
        {
            return $this->superAdmin;
        }
    };
}

function foundationOAuthManagerWithResponses(array $responses): OAuthManager
{
    $manager = new OAuthManager();
    $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    $prop = new ReflectionProperty($manager, 'client');
    $prop->setValue($manager, $client);

    return $manager;
}

// =========================================================================
// 1. ModuleValidator
// =========================================================================

it('covers all ModuleValidator validation branches including valid and invalid modules', function (): void {
    $validator = new ModuleValidator();

    // Invalid metadata, uninstalled package, mismatched deps, missing provider, bad PHP/Laravel, non-presentation plugin
    $invalid = foundationMakeManifest([
        'name' => 'invalid',
        'version' => '999.0.0',
        'provider' => 'Missing\\Provider',
        'requires' => [
            'packages' => ['liberusoftware/missing' => '^1.0'],
            'capabilities' => ['missing' => '^1.0'],
            'php' => '>999',
            'laravel' => '>999',
        ],
        'presentation' => ['filament' => ['admin' => ['Missing\\Plugin']]],
    ], 'liberusoftware/missing-module');

    file_put_contents($invalid->path.'/composer.json', json_encode([
        'name' => 'liberusoftware/missing-module',
        'type' => 'library',
        'extra' => ['liberu' => ['name' => 'different']],
        'require' => [],
    ], JSON_THROW_ON_ERROR));

    $errors = $validator->validate(new ModuleRegistry(['invalid' => $invalid]), '12.0.0');
    expect(implode("\n", $errors))->toContain(
        'does not match',
        'type must be',
        'not installed',
        'dependencies differ',
        'not autoloadable',
        'PHP',
        'Laravel',
        'only presentation',
        'presentation plugin',
    );

    // Installed package version mismatch + non-ServiceProvider class
    $mismatch = foundationMakeManifest([
        'name' => 'module-manager',
        'version' => '999.0.1',
        'provider' => stdClass::class,
    ], 'liberusoftware/module-manager');

    file_put_contents($mismatch->path.'/composer.json', json_encode([
        'name' => 'liberusoftware/module-manager',
        'type' => 'liberu-module',
        'extra' => ['liberu' => ['name' => 'module-manager']],
        'require' => [],
    ], JSON_THROW_ON_ERROR));

    $mismatchErrors = $validator->validate(new ModuleRegistry(['module-manager' => $mismatch]), '13.0.0');
    expect(implode("\n", $mismatchErrors))->toContain(
        'installed Composer version does not match',
        'provider must extend',
    );

    // Missing package name in composer.json
    $noName = foundationMakeManifest([
        'name' => 'noname',
        'version' => '1.0.0',
        'provider' => WebhooksServiceProvider::class,
    ], 'liberusoftware/noname');
    file_put_contents($noName->path.'/composer.json', json_encode([
        'type' => 'liberu-module',
        'extra' => ['liberu' => ['name' => 'noname']],
        'require' => [],
    ], JSON_THROW_ON_ERROR));
    expect(implode("\n", $validator->validate(new ModuleRegistry(['noname' => $noName]), '12.0.0')))
        ->toContain('not installed');

    // Exact version match + canonical package name + compatible patch version
    $installedVer = InstalledVersions::getPrettyVersion('liberusoftware/module-manager');
    $exact = foundationMakeManifest([
        'name' => 'module-manager',
        'version' => ltrim((string) $installedVer, 'v'),
        'provider' => WebhooksServiceProvider::class,
    ], 'liberusoftware/module-manager');
    file_put_contents($exact->path.'/composer.json', json_encode([
        'name' => 'liberusoftware/module-manager',
        'type' => 'liberu-module',
        'extra' => ['liberu' => ['name' => 'module-manager']],
        'require' => [],
    ], JSON_THROW_ON_ERROR));

    expect($validator->validate(new ModuleRegistry(['module-manager' => $exact]), '12.0.0'))->toBe([]);
});

// =========================================================================
// 2. ThemeDiscovery & ThemeManager
// =========================================================================

it('covers ThemeDiscovery valid and error branches', function (): void {
    $root = sys_get_temp_dir().'/host-theme-disc-'.bin2hex(random_bytes(5));
    mkdir($root.'/empty-dir', 0777, true);
    mkdir($root.'/alpha', 0777, true);
    foundationWriteTheme($root.'/alpha', ['name' => 'alpha']);

    // Dedupe same directory passed in tracked and installedPaths
    $discovered = (new ThemeDiscovery([$root.'/alpha', $root.'/nonexistent']))->discover($root);
    expect(array_keys($discovered))->toBe(['alpha']);

    // Default installedPaths() via Composer
    $fromComposer = (new ThemeDiscovery())->discover(base_path('themes'));
    expect($fromComposer)->not->toBeEmpty();

    // Directory/name mismatch in tracked tree
    $mismatchRoot = sys_get_temp_dir().'/host-theme-mis-'.bin2hex(random_bytes(5));
    foundationWriteTheme($mismatchRoot.'/wrongdir', ['name' => 'rightname']);
    expect(fn () => (new ThemeDiscovery([]))->discover($mismatchRoot))
        ->toThrow(InvalidTheme::class, 'Theme directory/name collision detected.');

    // Duplicate name across two different directories
    $dupA = sys_get_temp_dir().'/host-theme-dupa-'.bin2hex(random_bytes(5));
    $dupB = sys_get_temp_dir().'/host-theme-dupb-'.bin2hex(random_bytes(5));
    foundationWriteTheme($dupA.'/dup', ['name' => 'dup']);
    foundationWriteTheme($dupB.'/other', ['name' => 'dup']);
    expect(fn () => (new ThemeDiscovery([$dupB.'/other']))->discover($dupA))
        ->toThrow(InvalidTheme::class, 'Theme directory/name collision detected.');

    // Missing composer.json
    $noComposer = sys_get_temp_dir().'/host-theme-nocomp-'.bin2hex(random_bytes(5));
    foundationWriteTheme($noComposer.'/plain', ['name' => 'plain'], null);
    expect(fn () => (new ThemeDiscovery([]))->discover($noComposer))
        ->toThrow(InvalidTheme::class, 'has no composer.json');

    // Inconsistent composer metadata
    $badComposer = sys_get_temp_dir().'/host-theme-badcomp-'.bin2hex(random_bytes(5));
    foundationWriteTheme($badComposer.'/badc', ['name' => 'badc'], ['type' => 'library']);
    expect(fn () => (new ThemeDiscovery([]))->discover($badComposer))
        ->toThrow(InvalidTheme::class, 'Composer metadata is inconsistent');

    // Non-autoloadable provider
    $badProvider = sys_get_temp_dir().'/host-theme-badprov-'.bin2hex(random_bytes(5));
    foundationWriteTheme($badProvider.'/badp', ['name' => 'badp', 'provider' => 'Missing\\ThemeProvider']);
    expect(fn () => (new ThemeDiscovery([]))->discover($badProvider))
        ->toThrow(InvalidTheme::class, 'is not autoloadable');

    // Empty themes directory
    $emptyRoot = sys_get_temp_dir().'/host-theme-empty-'.bin2hex(random_bytes(5));
    mkdir($emptyRoot, 0777, true);
    expect(fn () => (new ThemeDiscovery([]))->discover($emptyRoot))
        ->toThrow(InvalidTheme::class, 'No themes are installed.');
});

it('covers ThemeManager full lifecycle, caching, inheritance, views, assets, capabilities, and fallbacks', function (): void {
    $origCache = config('theme.cache');
    $origCachePath = config('theme.cache_path');
    $origFallback = config('theme.fallback');
    $origDefault = config('theme.default');

    try {
        config()->set('theme.cache', false);
        config()->set('theme.fallback', 'default');
        config()->set('theme.default', 'default');

        $manager = new ThemeManager();
        expect($manager->selectForSurface('unknown'))->toBe('default')
            ->and($manager->getSelectedTheme())->toBe('default')
            ->and($manager->getActiveTheme())->toBe('default')
            ->and($manager->themeExists('default'))->toBeTrue()
            ->and($manager->themeExists('missing'))->toBeFalse()
            ->and($manager->getThemes())->not->toBeEmpty()
            ->and($manager->providers())->not->toBeEmpty()
            ->and($manager->getThemeAssetPath('missing'))->toBeNull()
            ->and($manager->activeEntries())->toContain('resources/js/app.js')
            ->and($manager->getThemeConfig('missing'))->toBe([])
            ->and($manager->primaryColor('missing'))->toBe('amber')
            ->and($manager->hasCustomLayout('missing'))->toBeFalse()
            ->and($manager->getLayout('app'))->toBe('layouts.app')
            ->and($manager->assetUrl('css/app.css'))->toContain('themes/default/css/app.css');

        expect(fn () => $manager->assetUrl('/unsafe'))->toThrow(InvalidTheme::class)
            ->and(fn () => $manager->assetUrl('../unsafe'))->toThrow(InvalidTheme::class)
            ->and(fn () => $manager->inheritanceChain('missing'))->toThrow(InvalidTheme::class);

        // Fallback when selecting incompatible/missing theme
        $manager->setTheme('nonexistent-theme');
        expect($manager->getActiveTheme())->toBe('default');

        // persistTheme with guest and authenticated user model
        $manager->persistTheme('default');
        expect(session('theme_preference'))->toBe('default');

        $user = new class() extends Authenticatable
        {
            public ?string $savedTheme = null;

            public function update(array $attributes = [], array $options = []): bool
            {
                $this->savedTheme = $attributes['theme_preference'] ?? null;

                return true;
            }
        };
        $this->actingAs($user);
        $manager->persistTheme('default');
        expect($user->savedTheme)->toBe('default');

        // getSiteTheme success and exception fallback
        $origSiteSettings = app()->bound(SiteSettings::class) ? app(SiteSettings::class) : null;
        app()->instance(SiteSettings::class, (object) ['active_theme' => 'default']);
        expect($manager->getSiteTheme())->toBe('default');

        app()->bind(SiteSettings::class, fn () => throw new RuntimeException('settings unavailable'));
        expect($manager->getSiteTheme())->toBe('default');
        if ($origSiteSettings !== null) {
            app()->instance(SiteSettings::class, $origSiteSettings);
        } else {
            app()->offsetUnset(SiteSettings::class);
        }

        // Custom cached themes: parent/child inheritance, layout, module/vendor overrides, describeViewSource, capabilities, cycle
        $tmpBase = sys_get_temp_dir().'/host-theme-mgr-'.bin2hex(random_bytes(5));
        $parentDir = $tmpBase.'/default';
        $childDir = $tmpBase.'/child';
        mkdir($parentDir.'/resources/views/layouts', 0777, true);
        mkdir($parentDir.'/resources/views/modules/demo', 0777, true);
        mkdir($childDir.'/resources/views/vendor/demo', 0777, true);
        file_put_contents($parentDir.'/resources/views/layouts/custom.blade.php', 'layout');
        file_put_contents($parentDir.'/resources/views/theme-only.blade.php', 'theme');
        file_put_contents($parentDir.'/app.css', 'body{}');
        file_put_contents($childDir.'/resources/views/vendor/demo/sample.blade.php', 'override');

        $parentManifest = ThemeManifest::fromFile(foundationWriteTheme($parentDir, [
            'name' => 'default',
            'assets' => ['css' => ['app.css'], 'js' => []],
        ]));
        $childManifest = ThemeManifest::fromFile(foundationWriteTheme($childDir, [
            'name' => 'child',
            'parent' => 'default',
            'required_capabilities' => ['demo.cap'],
        ]));

        $cachePath = $tmpBase.'/themes.cache';
        (new ThemeCache())->write(['default' => $parentManifest, 'child' => $childManifest], $cachePath);
        config()->set('theme.cache', true);
        config()->set('theme.cache_path', $cachePath);

        $cachedManager = new ThemeManager($tmpBase);
        expect($cachedManager->hasCustomLayout('custom', 'child'))->toBeTrue()
            ->and($cachedManager->getThemeJs())->toBeNull();

        // Register namespace hint and exercise registerThemePaths + describeViewSource (theme, module, application)
        $origFinder = View::getFinder();
        $appViews = $tmpBase.'/app-views';
        $modViews = $tmpBase.'/mod-views';
        mkdir($appViews, 0777, true);
        mkdir($modViews, 0777, true);
        file_put_contents($appViews.'/app-only.blade.php', 'app');
        file_put_contents($modViews.'/mod-only.blade.php', 'mod');

        $finder = new FileViewFinder(app('files'), [$appViews]);
        $finder->addNamespace('demo', $modViews);
        View::setFinder($finder);

        try {
            $cachedManager->setTheme('default');
            expect($cachedManager->describeViewSource('theme-only')['source'])->toBe('theme')
                ->and($cachedManager->describeViewSource('app-only')['source'])->toBe('application')
                ->and($cachedManager->describeViewSource('demo::mod-only')['source'])->toBe('module');

            // Non-FileViewFinder early return in registerThemePaths
            $customFinder = Mockery::mock(ViewFinderInterface::class);
            View::setFinder($customFinder);
            $cachedManager->registerThemePaths();
        } finally {
            View::setFinder($origFinder);
        }

        // Capabilities check with bound ModuleRegistry (both miss and cache hit) and unbound ModuleRegistry
        $capManifest = foundationMakeManifest(['name' => 'capmod', 'capabilities' => ['demo.cap']], 'local/capmod');
        $origRegistry = app()->bound(ModuleRegistry::class) ? app(ModuleRegistry::class) : null;
        app()->instance(ModuleRegistry::class, new ModuleRegistry(['capmod' => $capManifest]));
        config()->set('modules.enabled', []);
        config()->set('modules.disabled', []);
        expect($cachedManager->themeIsCompatible('child'))->toBeTrue()
            ->and($cachedManager->themeIsCompatible('child'))->toBeTrue();

        app()->forgetInstance(ModuleRegistry::class);
        app()->offsetUnset(ModuleRegistry::class);
        expect($cachedManager->themeIsCompatible('child'))->toBeFalse();
        if ($origRegistry !== null) {
            app()->instance(ModuleRegistry::class, $origRegistry);
        }

        // viteHasAsset with and without manifest + firstAsset existing file
        $manifestFile = public_path('build/manifest.json');
        $hadManifest = File::isFile($manifestFile);
        $origManifestContent = $hadManifest ? File::get($manifestFile) : null;
        try {
            if ($hadManifest) {
                File::delete($manifestFile);
            }
            expect($cachedManager->viteHasAsset('themes/default/app.css'))->toBeFalse();

            File::ensureDirectoryExists(dirname($manifestFile));
            File::put($manifestFile, json_encode(['themes/default/app.css' => ['file' => 'assets/app.css']]));
            expect($cachedManager->viteHasAsset('themes/default/app.css'))->toBeTrue();
        } finally {
            if ($hadManifest && $origManifestContent !== null) {
                File::put($manifestFile, $origManifestContent);
            } else {
                File::delete($manifestFile);
            }
        }

        // clearCache
        $cachedManager->clearCache();
        expect(is_file($cachePath))->toBeFalse();

        // Missing deployment cache
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'no deployment cache');

        // Empty deployment cache -> fallback missing
        (new ThemeCache())->write([], $cachePath);
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'Safe fallback theme [default] is not installed.');

        // Inheritance cycle
        $cycA = ThemeManifest::fromFile(foundationWriteTheme($tmpBase.'/cyc-a', ['name' => 'default', 'parent' => 'cyc-b']));
        $cycB = ThemeManifest::fromFile(foundationWriteTheme($tmpBase.'/cyc-b', ['name' => 'cyc-b', 'parent' => 'default']));
        (new ThemeCache())->write(['default' => $cycA, 'cyc-b' => $cycB], $cachePath);
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'Theme inheritance cycle');

        // Missing parent theme
        $orphan = ThemeManifest::fromFile(foundationWriteTheme($tmpBase.'/orphan', ['name' => 'default', 'parent' => 'absent']));
        (new ThemeCache())->write(['default' => $orphan], $cachePath);
        expect(fn () => new ThemeManager())->toThrow(InvalidTheme::class, 'Missing parent theme [absent]');
    } finally {
        config()->set('theme.cache', $origCache);
        config()->set('theme.cache_path', $origCachePath);
        config()->set('theme.fallback', $origFallback);
        config()->set('theme.default', $origDefault);
    }
});

// =========================================================================
// 3. theme-support-livewire
// =========================================================================

it('covers ThemeSupportLivewireServiceProvider and ThemeSwitcher', function (): void {
    $provider = $this->app->register(ThemeSupportLivewireServiceProvider::class, force: true);
    $provider->boot();

    $origCache = config('theme.cache');
    $origCachePath = config('theme.cache_path');
    $origFallback = config('theme.fallback');

    try {
        $tmpBase = sys_get_temp_dir().'/host-theme-sw-'.bin2hex(random_bytes(5));
        $defaultManifest = ThemeManifest::fromFile(foundationWriteTheme($tmpBase.'/default', ['name' => 'default']));
        $darkManifest = ThemeManifest::fromFile(foundationWriteTheme($tmpBase.'/dark', ['name' => 'dark']));
        $cachePath = $tmpBase.'/themes.cache';
        (new ThemeCache())->write(['default' => $defaultManifest, 'dark' => $darkManifest], $cachePath);

        config()->set('theme.cache', true);
        config()->set('theme.cache_path', $cachePath);
        config()->set('theme.fallback', 'default');

        $manager = new ThemeManager($tmpBase);
        $this->app->instance(ThemeManager::class, $manager);

        Livewire::test(ThemeSwitcher::class)
            ->assertSet('currentTheme', 'default')
            ->call('switchTheme', 'missing')
            ->assertNoRedirect();

        Livewire::withHeaders(['Referer' => 'https://attacker.example/steal'])
            ->test(ThemeSwitcher::class)
            ->call('switchTheme', 'dark')
            ->assertSet('currentTheme', 'dark')
            ->assertDispatched('theme-changed', theme: 'dark')
            ->assertRedirect('/');

        $safeUrl = url('/');
        Livewire::withHeaders(['Referer' => $safeUrl])
            ->test(ThemeSwitcher::class)
            ->call('switchTheme', 'dark')
            ->assertSet('currentTheme', 'dark')
            ->assertRedirect($safeUrl);
    } finally {
        config()->set('theme.cache', $origCache);
        config()->set('theme.cache_path', $origCachePath);
        config()->set('theme.fallback', $origFallback);
    }
});

// =========================================================================
// 4. webhooks
// =========================================================================

it('covers webhooks service provider, actions, models, signer, retry schedule, vault, and URL policy', function (): void {
    $provider = $this->app->register(WebhooksServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    Http::fake([
        'https://8.8.8.8/ok' => Http::response('accepted', 202),
        'https://8.8.8.8/fail' => Http::response('error', 500),
        'https://8.8.8.8/throw' => fn () => throw new RuntimeException('boom'),
    ]);

    $register = app(RegisterEndpoint::class);
    $endpoint = $register->handle('owner-1', 'https://8.8.8.8/ok', ['post.created'], 'initial-secret');
    expect($endpoint)->toBeInstanceOf(WebhookEndpoint::class)
        ->and(app(SigningSecretVault::class)->open($endpoint->signing_secret))->toBe('initial-secret');

    // Auto-generated secret when null
    $autoEndpoint = $register->handle('owner-1', 'https://8.8.8.8/fail', ['post.failed']);
    expect(strlen(app(SigningSecretVault::class)->open($autoEndpoint->signing_secret)))->toBe(64);

    // Invalid endpoint URL and empty events rejected
    expect(fn () => $register->handle('owner-1', 'ftp://example.test/hooks', ['post.created']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $register->handle('owner-1', 'https://8.8.8.8/ok', []))
        ->toThrow(InvalidArgumentException::class, 'At least one webhook event is required.');

    // QueueWebhook: queues once, deduplicates second call, ignores unsubscribed event
    $queue = app(QueueWebhook::class);
    expect($queue->handle('00000000-0000-0000-0000-000000000001', 'post.created', ['post_id' => '1']))->toBe(1)
        ->and($queue->handle('00000000-0000-0000-0000-000000000001', 'post.created', ['post_id' => '1']))->toBe(0)
        ->and($queue->handle('00000000-0000-0000-0000-000000000002', 'other.event', []))->toBe(0);

    // DeliverWebhook: success, HTTP failure, exception
    $deliver = app(DeliverWebhook::class);
    $delivery = FoundationWebhookDelivery::query()->firstOrFail();
    expect($deliver->handle($delivery)->status)->toBe('delivered')
        ->and($delivery->refresh()->attempts)->toBe(1)
        ->and($delivery->endpoint)->toBeInstanceOf(WebhookEndpoint::class);

    $queue->handle('00000000-0000-0000-0000-000000000003', 'post.failed', ['x' => 1]);
    $failedDelivery = FoundationWebhookDelivery::query()->where('event', 'post.failed')->firstOrFail();
    expect($deliver->handle($failedDelivery)->status)->toBe('failed')
        ->and($failedDelivery->refresh()->response_status)->toBe(500);

    $throwEndpoint = $register->handle('owner-1', 'https://8.8.8.8/throw', ['post.throw']);
    $queue->handle('00000000-0000-0000-0000-000000000004', 'post.throw', []);
    $throwDelivery = FoundationWebhookDelivery::query()->where('event', 'post.throw')->firstOrFail();
    expect($deliver->handle($throwDelivery)->status)->toBe('failed')
        ->and($throwDelivery->refresh()->response_excerpt)->toContain('boom');

    // RotateEndpointSecret
    $newSecret = app(RotateEndpointSecret::class)->handle($endpoint->refresh());
    expect($newSecret)->toHaveLength(64)
        ->and(app(SigningSecretVault::class)->open($endpoint->refresh()->signing_secret))->toBe($newSecret);

    // RetrySchedule
    $schedule = app(RetrySchedule::class);
    expect($schedule->seconds(0))->toBe(30)
        ->and($schedule->seconds(2))->toBe(60)
        ->and($schedule->seconds(20))->toBe(61440);

    // WebhookSigner
    $signer = app(WebhookSigner::class);
    $ts = time();
    $sig = $signer->sign('{"ok":true}', 'sec', $ts);
    expect($signer->verify('{"ok":true}', 'sec', $ts, $sig))->toBeTrue()
        ->and($signer->verify('{"ok":true}', 'sec', $ts - 600, $sig))->toBeFalse()
        ->and($signer->verify('{"ok":true}', 'sec', $ts, 'bad'))->toBeFalse();

    // WebhookUrlPolicy branches
    $policy = new class() extends WebhookUrlPolicy
    {
        /** @var list<string> */
        public array $mockAddresses = [];

        public bool $useRealDns = false;

        protected function addresses(string $host): array
        {
            if ($this->useRealDns) {
                return parent::addresses($host);
            }

            return $this->mockAddresses;
        }
    };

    expect(fn () => $policy->options('https://user:pass@8.8.8.8/hook'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $policy->options('https://127.0.0.1/hook'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $policy->options('https://[::ffff:127.0.0.1]/hook'))->toThrow(InvalidArgumentException::class);

    $policy->mockAddresses = [];
    expect(fn () => $policy->options('https://unresolved.example.invalid/hook'))->toThrow(InvalidArgumentException::class, 'could not be resolved');

    $policy->mockAddresses = ['8.8.8.8', '2001:4860:4860::8888'];
    $opts = $policy->options('https://dns.example.test/hook');
    expect($opts['curl'][CURLOPT_RESOLVE][0])->toContain('dns.example.test:443:8.8.8.8,[2001:4860:4860::8888]');

    $policy->useRealDns = true;
    $googleOpts = $policy->options('https://google.com/hook');
    expect($googleOpts['curl'][CURLOPT_RESOLVE][0])->toContain('google.com:443:');
    try {
        $policy->options('https://nonexistent.invalid/hook');
    } catch (InvalidArgumentException) {
        // Expected when DNS fails to resolve
    }
});

// =========================================================================
// 5. identity-socialstream
// =========================================================================

it('covers identity-socialstream provider, models, policy, actions, telegram, SPA response, and listener', function (): void {
    $provider = $this->app->register(SocialstreamServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    $factoryAccount = ConnectedAccount::factory()->make([
        'token' => 'secret-tok',
        'secret' => 'secret-sec',
        'refresh_token' => 'secret-ref',
    ]);
    $serialized = $factoryAccount->toArray();

    expect($factoryAccount)->toBeInstanceOf(ConnectedAccount::class)
        ->and($serialized)->not->toHaveKey('token')
        ->and($serialized)->not->toHaveKey('secret')
        ->and($serialized)->not->toHaveKey('refresh_token');

    $owner = new class() extends Authenticatable implements ConnectedAccountOwner
    {
        public bool $owns = true;

        public function ownsConnectedAccount($account): bool
        {
            return $this->owns;
        }

        public function hasRole(): bool
        {
            return false;
        }
    };

    $policy = new ConnectedAccountPolicy();
    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue()
        ->and($policy->view($owner, $factoryAccount))->toBeTrue()
        ->and($policy->update($owner, $factoryAccount))->toBeTrue()
        ->and($policy->delete($owner, $factoryAccount))->toBeTrue();

    $owner->owns = false;
    expect($policy->view($owner, $factoryAccount))->toBeFalse()
        ->and($policy->update($owner, $factoryAccount))->toBeFalse()
        ->and($policy->delete($owner, $factoryAccount))->toBeFalse();

    Socialstream::useConnectedAccountModel(ConnectedAccount::class);
    Gate::policy(ConnectedAccount::class, ConnectedAccountPolicy::class);

    $createAccount = new CreateConnectedAccount();
    $providerUser = (new SocialiteUser())->map([
        'id' => 'prov-100',
        'name' => 'OAuth User',
        'nickname' => 'oauth_u',
        'email' => 'oauth.cov@example.test',
        'avatar' => null,
    ])->setToken('tok-1')->setRefreshToken('ref-1')->setExpiresIn(3600);

    expect(fn () => $createAccount->create(new stdClass(), 'google', $providerUser))
        ->toThrow(InvalidArgumentException::class, 'Connected accounts require an Eloquent user model.');

    $user = ErpUser::factory()->create();
    $account = $createAccount->create($user, 'Google', $providerUser);

    expect($account)->toBeInstanceOf(ConnectedAccount::class)
        ->and($account->provider)->toBe('google')
        ->and($account->provider_id)->toBe('prov-100')
        ->and($account->expires_at)->not->toBeNull();

    $owner->owns = true;
    $updatedProviderUser = (new SocialiteUser())->map([
        'id' => 'prov-100',
        'name' => 'OAuth User Updated',
        'nickname' => 'oauth_u2',
        'email' => 'oauth.cov@example.test',
        'avatar' => null,
    ])->setToken('tok-2')->setRefreshToken('ref-2')->setExpiresIn(7200);

    $updateAccount = new UpdateConnectedAccount();
    $updated = $updateAccount->update($owner, $account, 'GOOGLE', $updatedProviderUser);
    expect($updated->name)->toBe('OAuth User Updated')
        ->and($updated->token)->toBe('tok-2');

    Socialstream::refreshesTokensForProviderUsing('google', fn (): RefreshedCredentials => new RefreshedCredentials(
        token: 'refreshed-tok',
        tokenSecret: 'refreshed-sec',
        refreshToken: 'refreshed-ref',
        expiry: now()->addHours(2),
    ));
    $refreshed = $updateAccount->updateRefreshToken($updated);
    expect($refreshed->token)->toBe('refreshed-tok')
        ->and($refreshed->secret)->toBe('refreshed-sec')
        ->and($refreshed->refresh_token)->toBe('refreshed-ref');

    config()->set('jetstream.features', [Laravel\Jetstream\Features::profilePhotos()]);
    config()->set('socialstream.features', [Features::providerAvatars()]);
    Http::swap(new Factory());
    Http::fake(['https://avatar.example.test/*' => Http::response('', 404)]);
    $newProviderUser = (new SocialiteUser())->map([
        'id' => 'prov-200',
        'name' => 'Social Newcomer',
        'nickname' => 'newcomer',
        'email' => 'newcomer.cov@example.test',
        'avatar' => 'https://avatar.example.test/avatar.png',
    ])->setToken('tok-new');

    $creator = new CreateUserFromProvider($createAccount);
    $createdUser = $creator->create('google', $newProviderUser);

    expect($createdUser->email)->toBe('newcomer.cov@example.test')
        ->and($createdUser->email_verified_at)->not->toBeNull()
        ->and($createdUser->ownedTeams()->count())->toBe(1);

    $setPassword = new SetUserPassword();
    expect(fn () => $setPassword->set(new stdClass(), ['password' => 'StrongPass#1234', 'password_confirmation' => 'StrongPass#1234']))
        ->toThrow(InvalidArgumentException::class, 'Passwords can only be set on Eloquent user models.');

    $setPassword->set($user, [
        'password' => 'StrongPass#1234',
        'password_confirmation' => 'StrongPass#1234',
    ]);
    expect(Hash::check('StrongPass#1234', $user->fresh()->password))->toBeTrue();

    // GenerateRedirectForProvider, ResolveSocialiteUser, HandleInvalidState
    $expectedRedirect = new RedirectResponse('https://accounts.google.com/o/oauth2/auth');
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('redirect')->once()->andReturn($expectedRedirect);

    $socialiteUser = (new SocialiteUser())->map([
        'id' => 'no-email-42',
        'name' => 'No Email',
        'email' => null,
    ]);
    $driver->shouldReceive('user')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($driver);

    expect((new GenerateRedirectForProvider())->generate('google'))->toBe($expectedRedirect);

    config()->set('socialstream.features', [Features::generateMissingEmails()]);
    config()->set('app.domain', '.example.test');
    $resolved = (new ResolveSocialiteUser())->resolve('google');
    expect($resolved->getEmail())->toBe('no-email-42@google.example.test');

    expect(fn () => (new HandleInvalidState())->handle(new InvalidStateException('bad state')))
        ->toThrow(InvalidStateException::class, 'bad state');

    // Telegram verification, TelegramAuthController, SpaAwareOAuthResponse, SyncNewOAuthUserToCrm
    $botToken = '999999:cov-telegram-token';
    $verifier = new VerifyTelegramAuthPayload($botToken);
    $now = now()->timestamp;

    $sign = static function (array $fields) use ($botToken): array {
        ksort($fields);
        $check = collect($fields)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $fields['hash'] = hash_hmac('sha256', $check, hash('sha256', $botToken, true));

        return $fields;
    };

    expect($verifier->verify(Request::create('/cb', 'GET', ['id' => ['bad'], 'auth_date' => (string) $now, 'hash' => 'x'])))->toBeNull()
        ->and($verifier->verify(Request::create('/cb', 'GET', ['id' => '1'])))->toBeNull();

    $valid = $sign(['id' => '777', 'first_name' => 'Ada', 'last_name' => 'L', 'username' => 'ada', 'auth_date' => (string) $now]);
    expect($verifier->verify(Request::create('/cb', 'GET', $valid)))->toBeArray();
    expect($verifier->verify(Request::create('/cb', 'GET', [...$valid, 'hash' => str_repeat('0', 64)])))->toBeNull();
    expect($verifier->verify(Request::create('/cb', 'GET', $sign(['id' => '777', 'auth_date' => (string) ($now - 90000)]))))->toBeNull();

    config()->set('services.telegram.bot_token', $botToken);
    config()->set('app.url', 'https://erp.example.test');

    $controller = new TelegramAuthController();
    $auth = Mockery::mock(AuthenticatesOAuthCallback::class);
    expect(fn () => $controller->callback(Request::create('/oauth/telegram/callback', 'GET'), $auth))
        ->toThrow(HttpException::class);

    $expectedResp = new RedirectResponse('/home');
    $auth->shouldReceive('authenticate')->once()->andReturn($expectedResp);
    expect($controller->callback(Request::create('/oauth/telegram/callback', 'GET', [...$valid, 'spa' => '1']), $auth))
        ->toBe($expectedResp);

    $defaultResp = new SymfonyResponse('default-ok');
    $default = new class($defaultResp) implements Responsable
    {
        public function __construct(private readonly SymfonyResponse $resp) {}

        public function toResponse($request): SymfonyResponse
        {
            return $this->resp;
        }
    };
    $spa = new SpaAwareOAuthResponse($default);

    session()->forget('socialstream.spa_redirect');
    $req = Request::create('/oauth/google/callback', 'GET');
    expect($spa->toResponse($req))->toBe($defaultResp);

    session(['socialstream.spa_redirect' => true]);
    $req->setUserResolver(fn () => new class()
    {
        public int $id = 5;

        public string $name = 'SPA User';

        public string $email = 'spa@example.test';

        public function createToken(string $name): object
        {
            return (object) ['plainTextToken' => 'spa-tok-1'];
        }
    });
    expect($spa->toResponse($req))->toBeInstanceOf(RedirectResponse::class);

    Bus::fake();
    $oauthUser = (object) ['id' => 5, 'currentTeam' => null, 'created_at' => now()];
    $providerAcc = (new SocialiteUser())->map(['id' => 'g-1', 'nickname' => 'spa']);
    (new SyncNewOAuthUserToCrm())->handle(new NewOAuthRegistration($oauthUser, 'google', $providerAcc));
    Bus::assertDispatched(SyncNewUserToCrm::class);
});

// =========================================================================
// 6. identity-core-filament
// =========================================================================

it('covers identity-core-filament plugin, provider, UserResource, closures, and pages', function (): void {
    $provider = $this->app->register(IdentityFilamentServiceProvider::class, force: true);
    expect($provider)->toBeInstanceOf(IdentityFilamentServiceProvider::class);

    $plugin = IdentityFilamentPlugin::make();
    expect($plugin->getId())->toBe('liberu-identity');

    $panel = Panel::make()->id('identity-host-coverage-panel');
    $plugin->register($panel);
    $plugin->boot($panel);

    expect($panel->getResources())->toContain(UserResource::class)
        ->and(UserResource::getModel())->toBe(config('auth.providers.users.model'))
        ->and(UserResource::isScopedToTenant())->toBeFalse()
        ->and(array_keys(UserResource::getPages()))->toBe(['index', 'create', 'edit']);

    $listPage = new class() extends ListUsers
    {
        public function exposedHeaderActions(): array
        {
            return $this->getHeaderActions();
        }
    };

    $editPage = new class() extends EditUser
    {
        public function exposedHeaderActions(): array
        {
            return $this->getHeaderActions();
        }
    };

    $createPage = new CreateUser();
    expect($createPage)->toBeInstanceOf(CreateUser::class);

    expect($listPage->exposedHeaderActions())->toHaveCount(1)
        ->and($listPage->exposedHeaderActions()[0])->toBeInstanceOf(CreateAction::class)
        ->and($editPage->exposedHeaderActions())->toHaveCount(1)
        ->and($editPage->exposedHeaderActions()[0])->toBeInstanceOf(DeleteAction::class);

    $schema = UserResource::form(Schema::make($listPage));
    $components = $schema->getComponents();
    expect($components)->toHaveCount(5);

    $passwordField = $components[2];
    $dehydrateState = (new ReflectionProperty($passwordField, 'dehydrateStateUsing'))->getValue($passwordField);
    expect(Hash::check('secret-123', $dehydrateState('secret-123')))->toBeTrue();

    $dehydrated = (new ReflectionProperty($passwordField, 'isDehydrated'))->getValue($passwordField);
    expect($dehydrated('non-empty'))->toBeTrue()
        ->and($dehydrated(''))->toBeFalse();

    $required = (new ReflectionProperty($passwordField, 'isRequired'))->getValue($passwordField);
    expect($required('create'))->toBeTrue()
        ->and($required('edit'))->toBeFalse();

    $tableLivewire = Mockery::mock(HasTable::class);
    $table = UserResource::table(Table::make($tableLivewire));
    $tableLivewire->shouldReceive('getTable')->andReturn($table);
    $tableLivewire->shouldReceive('getTableRecordKey')->andReturnUsing(fn (Model $r): string => (string) $r->getKey());
    $columns = $table->getColumns();
    expect(array_keys($columns))->toBe(['name', 'email', 'roles.name', 'email_verified_at', 'created_at']);

    $verifiedUser = ErpUser::factory()->create(['email_verified_at' => now()]);
    $unverifiedUser = ErpUser::factory()->create(['email_verified_at' => null]);

    /** @var IconColumn $verifiedColumn */
    $verifiedColumn = $columns['email_verified_at'];
    expect($verifiedColumn->record($verifiedUser)->getState())->toBeTrue()
        ->and($verifiedColumn->record($unverifiedUser)->getState())->toBeFalse();
});

// =========================================================================
// 7. integrations
// =========================================================================

it('covers integrations provider, enums, support, models, accounting, and DirectSocialApiClient', function (): void {
    $provider = new IntegrationsServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    $first = $this->app->make(IntegrationRegistry::class);
    $second = $this->app->make(IntegrationRegistry::class);
    expect($first)->toBeInstanceOf(IntegrationRegistry::class)->and($first)->toBe($second);

    $options = SocialPlatform::options();
    $values = SocialPlatform::values();
    expect($options)->toHaveCount(count(SocialPlatform::cases()))
        ->and($values)->toHaveCount(count(SocialPlatform::cases()));
    foreach (SocialPlatform::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }

    $vault = new CredentialVault();
    $payload = ['client_id' => 'abc-123', 'secret' => 's3cr3t', 'scopes' => ['read', 'write']];
    $sealed = $vault->seal($payload);
    expect($sealed)->not->toContain('s3cr3t')
        ->and($vault->open($sealed))->toBe($payload);

    $registry = new IntegrationRegistry();
    $basic = new class() implements IntegrationAdapter, PaymentProviderAdapter
    {
        public function name(): string
        {
            return 'basic-pay';
        }

        public function capabilities(): array
        {
            return ['payments'];
        }

        public function test(array $credentials): bool
        {
            return ! empty($credentials);
        }

        public function sendPayment(array $payment): array
        {
            return ['status' => 'sent', 'payment' => $payment];
        }

        public function sendBulkPayments(string $title, array $payments, ?string $scheduleFor = null): array
        {
            return ['title' => $title, 'count' => count($payments), 'schedule' => $scheduleFor];
        }
    };

    $oauth = new class() implements OAuthIntegrationAdapter
    {
        public function name(): string
        {
            return 'oauth-provider';
        }

        public function capabilities(): array
        {
            return ['oauth'];
        }

        public function test(array $credentials): bool
        {
            return true;
        }

        public function authorizationUrl(string $state): string
        {
            return 'https://example.com/auth?state='.$state;
        }

        public function exchangeAuthorizationCode(string $code): array
        {
            return ['access_token' => 'tok-'.$code];
        }

        public function refreshAccessToken(string $refreshToken): array
        {
            return ['access_token' => 'ref-'.$refreshToken];
        }
    };

    $registry->register($basic);
    $registry->register($oauth);
    expect($registry->get('basic-pay'))->toBe($basic)
        ->and($registry->oauth('oauth-provider'))->toBe($oauth)
        ->and($registry->all())->toHaveCount(2)
        ->and(interface_exists(CalendarService::class))->toBeTrue();

    expect(fn () => $registry->register($basic))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->get('missing'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->oauth('basic-pay'))->toThrow(InvalidArgumentException::class);

    // SsrfGuard
    SsrfGuard::assertPublicHttps('https://8.8.8.8/oidc');
    SsrfGuard::assertPublicHttps('https://nonexistent.example.invalid/oidc');
    foreach ([
        'http://example.com/oidc',
        'ftp://8.8.8.8/oidc',
        'not-a-url',
        'https:///no-host',
        'https://localhost/oidc',
        'https://sub.localhost/oidc',
        'https://127.0.0.1/oidc',
        'https://169.254.169.254/latest/meta-data',
        'https://10.0.0.1/internal',
        'https://[::1]/oidc',
    ] as $blocked) {
        expect(fn () => SsrfGuard::assertPublicHttps($blocked))->toThrow(InvalidArgumentException::class);
    }

    foreach (['localhost.localdomain', (string) gethostname()] as $localHost) {
        $resolved = gethostbynamel($localHost) ?: [];
        foreach ($resolved as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                expect(fn () => SsrfGuard::assertPublicHttps('https://'.$localHost.'/oidc'))
                    ->toThrow(InvalidArgumentException::class, 'non-public address');
                break 2;
            }
        }
    }

    // Models
    $user = AppUser::factory()->create();
    $team = OrgTeam::factory()->create(['user_id' => $user->id]);

    $accounting = AccountingIntegration::create([
        'user_id' => $user->id,
        'platform' => 'quickbooks',
        'connection_details' => ['realm_id' => '12345'],
        'last_synced' => now(),
    ]);
    expect($accounting->user->id)->toBe($user->id);

    $connection = IntegrationConnection::create([
        'scope_type' => 'team',
        'scope_id' => (string) $team->id,
        'provider' => 'stripe',
        'credentials' => 'encrypted-blob',
        'capabilities' => ['payments'],
        'status' => 'connected',
        'last_tested_at' => now(),
    ]);
    expect($connection->capabilities)->toBe(['payments']);

    $oauthModel = OAuthConfiguration::create([
        'service_name' => 'google',
        'account_name' => 'Workspace Google',
        'client_id' => 'cid-1',
        'client_secret' => 'csec-1',
        'access_token' => 'acc-1',
        'refresh_token' => 'ref-1',
        'token_expires_at' => now()->addHour(),
        'additional_settings' => ['region' => 'eu'],
        'is_active' => true,
    ]);
    expect(OAuthConfiguration::getConfig('google')?->id)->toBe($oauthModel->id)
        ->and(OAuthConfiguration::getConfig('nonexistent'))->toBeNull();

    $webhook = Webhook::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'name' => 'Main Hook',
        'url' => 'https://hooks.example.com/main',
        'events' => ['contact.created'],
        'secret' => 'top-secret',
    ]);
    $delivery = IntegrationWebhookDelivery::create([
        'team_id' => $team->id,
        'webhook_id' => $webhook->id,
        'event' => 'contact.created',
        'success' => true,
        'status_code' => 200,
    ]);
    expect($webhook->deliveries)->toHaveCount(1)
        ->and($delivery->webhook->id)->toBe($webhook->id);

    // AccountingService
    $acctSvc = new AccountingService(new QuickBooksService(), new XeroService());
    $qb = new AccountingIntegration(['platform' => 'quickbooks']);
    $xero = new AccountingIntegration(['platform' => 'xero']);
    $bad = new AccountingIntegration(['platform' => 'sage']);

    expect($acctSvc->syncInvoice($qb, 'i1')['provider'])->toBe('quickbooks')
        ->and($acctSvc->syncInvoice($xero, 'i2')['provider'])->toBe('xero')
        ->and($acctSvc->syncPayment($qb, 'p1')['provider'])->toBe('quickbooks')
        ->and($acctSvc->syncPayment($xero, 'p2')['provider'])->toBe('xero')
        ->and($acctSvc->connectPlatform('quickbooks', [])['provider'])->toBe('quickbooks')
        ->and($acctSvc->connectPlatform('xero', [])['provider'])->toBe('xero');
    expect(fn () => $acctSvc->syncInvoice($bad, 'i'))->toThrow(AccountingIntegrationException::class);
    expect(fn () => $acctSvc->syncPayment($bad, 'p'))->toThrow(AccountingIntegrationException::class);
    expect(fn () => $acctSvc->connectPlatform('sage', []))->toThrow(AccountingIntegrationException::class);

    // DirectSocialApiClient
    $platform = new PlatformConfiguration();
    $platform->currentProvider = 'direct';
    $this->app->instance(PlatformConfiguration::class, $platform);

    config([
        'services.zernio.direct_api_enabled' => true,
        'services.social.direct_base_urls.twitter' => 'https://api.twitter.test/v2/',
    ]);

    $client = new DirectSocialApiClient();
    $cfg = new OAuthConfiguration(['service_name' => 'twitter', 'access_token' => 'secret-token']);

    Http::fake([
        'https://api.twitter.test/v2/tweets' => Http::sequence()
            ->push(['id' => 't1'], 200)
            ->push('plain-text', 200)
            ->push(['error' => 'server error'], 500),
    ]);

    expect($client->request($cfg, 'post', 'tweets', ['text' => 'hello']))->toBe(['id' => 't1'])
        ->and($client->request($cfg, 'post', '/tweets', ['text' => 'hello']))->toBe([]);
    expect(fn () => $client->request($cfg, 'post', 'tweets', ['text' => 'hello']))
        ->toThrow(RuntimeException::class, 'status 500');

    expect(fn () => $client->request(new OAuthConfiguration(['service_name' => 'twitter', 'access_token' => '']), 'get', 'me'))
        ->toThrow(HttpException::class);

    config(['services.social.direct_base_urls.twitter' => 'not-a-url']);
    expect(fn () => $client->request($cfg, 'get', 'me'))->toThrow(HttpException::class);

    config(['services.social.direct_base_urls.twitter' => 'https://api.twitter.test/v2', 'services.zernio.direct_api_enabled' => false]);
    expect(fn () => $client->request($cfg, 'get', 'me'))->toThrow(HttpException::class);

    config(['services.zernio.direct_api_enabled' => true]);
    $platform->currentProvider = 'zernio';
    expect(fn () => $client->request($cfg, 'get', 'me'))->toThrow(HttpException::class);
});

it('covers integrations Filament resources, WebhookService, and WebhookController', function (): void {
    $regular = AppUser::factory()->create();
    $superAdmin = AppUser::factory()->create();
    $team = OrgTeam::factory()->create(['user_id' => $superAdmin->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
    $role = CustomRoleModel::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
        'team_id' => $team->id,
    ]);
    $superAdmin->assignRole($role);

    $record = new OAuthConfiguration();
    $this->actingAs($regular);
    expect(OAuthConfigurationResource::canViewAny())->toBeFalse()
        ->and(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canCreate())->toBeFalse()
        ->and(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canEdit($record))->toBeFalse()
        ->and(OAuthConfigurationResource::getEditAuthorizationResponse($record)->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canView($record))->toBeFalse()
        ->and(OAuthConfigurationResource::getViewAuthorizationResponse($record)->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canDelete($record))->toBeFalse()
        ->and(OAuthConfigurationResource::getDeleteAuthorizationResponse($record)->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canDeleteAny())->toBeFalse()
        ->and(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse();

    $this->actingAs($superAdmin);
    expect(OAuthConfigurationResource::canViewAny())->toBeTrue()
        ->and(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canCreate())->toBeTrue()
        ->and(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canEdit($record))->toBeTrue()
        ->and(OAuthConfigurationResource::getEditAuthorizationResponse($record)->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canView($record))->toBeTrue()
        ->and(OAuthConfigurationResource::getViewAuthorizationResponse($record)->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canDelete($record))->toBeTrue()
        ->and(OAuthConfigurationResource::getDeleteAuthorizationResponse($record)->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canDeleteAny())->toBeTrue()
        ->and(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::permissionResource())->toBe('oauth_configuration')
        ->and(OAuthConfigurationResource::getRelations())->toBe([])
        ->and(OAuthConfigurationResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $editPage = new EditOAuthConfiguration();
    $schema = OAuthConfigurationResource::form(Schema::make($editPage));
    foreach ($schema->getComponents() as $component) {
        if (method_exists($component, 'getName') && $component->getName() === 'client_secret') {
            $reqClosure = (new ReflectionProperty($component, 'isRequired'))->getValue($component);
            expect($reqClosure('create'))->toBeTrue()->and($reqClosure('edit'))->toBeFalse();
            $dehydClosure = (new ReflectionProperty($component, 'isDehydrated'))->getValue($component);
            expect($dehydClosure('new-secret'))->toBeTrue()->and($dehydClosure(''))->toBeFalse();
        }
    }

    $livewire = Mockery::mock(HasTable::class);
    expect(OAuthConfigurationResource::table(Table::make($livewire))->getColumns())->not->toBeEmpty();

    $createPage = new CreateOAuthConfiguration();
    expect($createPage::getResource())->toBe(OAuthConfigurationResource::class);
    expect((new ReflectionMethod($editPage, 'mutateFormDataBeforeFill'))->invoke($editPage, ['client_secret' => 's'])['client_secret'])->toBe('');
    expect((new ReflectionMethod($editPage, 'getHeaderActions'))->invoke($editPage))->toHaveCount(1);
    $listPage = new ListOAuthConfigurations();
    expect((new ReflectionMethod($listPage, 'getHeaderActions'))->invoke($listPage))->toHaveCount(1);

    // WebhookDeliveryResource
    auth()->logout();
    expect(WebhookDeliveryResource::canAccess())->toBeFalse();

    $user = AppUser::factory()->create();
    $wTeam = OrgTeam::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $wTeam->id])->save();
    $this->actingAs($user);

    $delRec = new IntegrationWebhookDelivery();
    expect(WebhookDeliveryResource::canAccess())->toBeFalse()
        ->and(WebhookDeliveryResource::canCreate())->toBeFalse()
        ->and(WebhookDeliveryResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(WebhookDeliveryResource::canEdit($delRec))->toBeFalse()
        ->and(WebhookDeliveryResource::getEditAuthorizationResponse($delRec)->allowed())->toBeFalse()
        ->and(WebhookDeliveryResource::canDelete($delRec))->toBeFalse()
        ->and(WebhookDeliveryResource::getDeleteAuthorizationResponse($delRec)->allowed())->toBeFalse();

    app(PermissionRegistrar::class)->setPermissionsTeamId($wTeam->id);
    $adminRole = CustomRoleModel::firstOrCreate(['name' => 'admin', 'guard_name' => 'web', 'team_id' => $wTeam->id]);
    $user->assignRole($adminRole);
    expect(WebhookDeliveryResource::canAccess())->toBeTrue()
        ->and(WebhookDeliveryResource::getEloquentQuery()->toSql())->toContain('team_id')
        ->and(WebhookDeliveryResource::table(Table::make($livewire))->getColumns())->not->toBeEmpty()
        ->and(WebhookDeliveryResource::getPages())->toHaveKey('index')
        ->and(ListWebhookDeliveries::getResource())->toBe(WebhookDeliveryResource::class);

    $allowedWebhookDeliveryResource = new class() extends WebhookDeliveryResource
    {
        public static function canCreate(): bool
        {
            return true;
        }

        public static function canEdit(Model $record): bool
        {
            return true;
        }

        public static function canDelete(Model $record): bool
        {
            return true;
        }
    };
    expect($allowedWebhookDeliveryResource::getCreateAuthorizationResponse())->toBeInstanceOf(Response::class)
        ->and($allowedWebhookDeliveryResource::getEditAuthorizationResponse($delRec))->toBeInstanceOf(Response::class)
        ->and($allowedWebhookDeliveryResource::getDeleteAuthorizationResponse($delRec))->toBeInstanceOf(Response::class);

    // WebhookService + WebhookController
    $service = new WebhookService();
    $webhook = $service->register([
        'name' => 'CRM Hook',
        'url' => 'https://hooks.example.com/events',
        'events' => ['contact.created'],
        'team_id' => $wTeam->id,
        'user_id' => $user->id,
    ]);
    $service->register([
        'name' => 'Inactive Hook',
        'url' => 'https://hooks.example.com/inactive',
        'events' => ['contact.created'],
        'secret' => 'custom-secret',
        'is_active' => false,
        'team_id' => $wTeam->id,
    ]);

    expect($service->verifySignature('{"a":1}', 'sha256='.hash_hmac('sha256', '{"a":1}', $webhook->secret), $webhook->secret))->toBeTrue()
        ->and($service->verifySignature('{"a":1}', 'sha256=wrong', $webhook->secret))->toBeFalse();

    $updated = $service->update($webhook, ['name' => 'Renamed Hook', 'is_active' => true]);
    $service->regenerateSecret($updated);

    Http::fake([
        'https://hooks.example.com/events' => Http::sequence()
            ->push(['ok' => true], 200)
            ->push('fail', 500)
            ->whenEmpty(fn () => throw new RuntimeException('network timeout')),
        '*' => Http::response('ok', 200),
    ]);
    $service->dispatch('contact.created', ['id' => 1]);

    expect($service->send($updated->fresh(), 'contact.created', ['id' => 2]))->toBeFalse();

    $updated->update(['failure_count' => 9, 'secret' => null]);
    expect($service->send($updated->fresh(), 'contact.created', ['id' => 3]))->toBeFalse()
        ->and($updated->fresh()->is_active)->toBeFalse();

    $prevEnv = $this->app['env'];
    try {
        $this->app['env'] = 'production';
        $internal = $service->register(['name' => 'Internal', 'url' => 'http://localhost/hook', 'events' => ['contact.created'], 'team_id' => $wTeam->id]);
        expect(fn () => $service->send($internal, 'contact.created', []))->toThrow(RuntimeException::class, 'internal IP');

        $hostless = $service->register(['name' => 'Hostless', 'url' => '/relative/path', 'events' => ['contact.created'], 'team_id' => $wTeam->id]);
        expect($service->send($hostless, 'contact.created', []))->toBeTrue();

        $public = $service->register(['name' => 'Public', 'url' => 'https://8.8.8.8/hook', 'events' => ['contact.created'], 'team_id' => $wTeam->id]);
        expect($service->send($public, 'contact.created', []))->toBeTrue();
    } finally {
        $this->app['env'] = $prevEnv;
    }

    $controller = new WebhookController(new WebhookService());
    $storeReq = Request::create('/api/webhooks', 'POST', [
        'name' => 'API Hook',
        'url' => 'https://8.8.8.8/hook',
        'events' => ['contact.created'],
    ]);
    $storeReq->setUserResolver(fn () => $user);
    $stored = $controller->store($storeReq);
    expect($stored->getStatusCode())->toBe(201);

    $wh = Webhook::withoutGlobalScopes()->findOrFail($stored->getData(true)['id']);
    $indexReq = Request::create('/api/webhooks', 'GET');
    $indexReq->setUserResolver(fn () => $user);
    expect($controller->index($indexReq)->getData(true))->not->toBeEmpty();

    $showReq = Request::create('/api/webhooks/'.$wh->id, 'GET');
    $showReq->setUserResolver(fn () => $user);
    expect($controller->show($showReq, $wh)->getData(true)['name'])->toBe('API Hook');

    $updateReq = Request::create('/api/webhooks/'.$wh->id, 'PATCH', ['name' => 'API Hook Updated', 'is_active' => false]);
    $updateReq->setUserResolver(fn () => $user);
    expect($controller->update($updateReq, $wh)->getData(true)['name'])->toBe('API Hook Updated');

    $regenReq = Request::create('/api/webhooks/'.$wh->id.'/regenerate', 'POST');
    $regenReq->setUserResolver(fn () => $user);
    expect($controller->regenerateSecret($regenReq, $wh)->getData(true))->toHaveKey('secret');
    expect($controller->events()->getData(true)['events'])->toBe(Webhook::EVENTS);

    $destroyReq = Request::create('/api/webhooks/'.$wh->id, 'DELETE');
    $destroyReq->setUserResolver(fn () => $user);
    expect($controller->destroy($destroyReq, $wh)->getStatusCode())->toBe(204);

    $teamless = AppUser::factory()->create();
    $teamless->forceFill(['current_team_id' => null])->save();
    $teamlessReq = Request::create('/api/webhooks', 'GET');
    $teamlessReq->setUserResolver(fn () => $teamless);
    expect($controller->index($teamlessReq)->getData(true))->toBe([]);

    try {
        $this->app['env'] = 'production';
        $goodProdReq = Request::create('/api/webhooks', 'POST', ['name' => 'Prod Hook', 'url' => 'https://8.8.8.8/prod-hook', 'events' => ['contact.created']]);
        $goodProdReq->setUserResolver(fn () => $user);
        expect($controller->store($goodProdReq)->getStatusCode())->toBe(201);

        $badProdReq = Request::create('/api/webhooks', 'POST', ['name' => 'Bad Hook', 'url' => 'http://127.0.0.1/hook', 'events' => ['contact.created']]);
        $badProdReq->setUserResolver(fn () => $user);
        expect(fn () => $controller->store($badProdReq))->toThrow(ValidationException::class);
    } finally {
        $this->app['env'] = $prevEnv;
    }
});

it('covers integrations OAuthManager, MessageService, AccountingIntegrationController, OAuthController, and OAuthConfigurationController', function (): void {
    Route::get('/_host_test/oauth/{provider}/callback', fn () => 'ok')->name('oauth.callback');
    Route::get('/_host_test/oauth/configurations', fn () => 'index')->name('oauth.configurations.index');
    Route::get('/_host_test/oauth/authenticate/{service}/{configId}', fn () => 'auth')->name('oauth.authenticate');
    Route::get('/_host_test/oauth/configurations/{service}/callback', fn () => 'cb')->name('oauth.configurations.callback');
    app('router')->getRoutes()->refreshNameLookups();

    if (! DbSchema::hasColumn('oauth_configurations', 'user_id')) {
        DbSchema::table('oauth_configurations', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable();
        });
    }
    if (! DbSchema::hasColumn('oauth_configurations', 'team_id')) {
        DbSchema::table('oauth_configurations', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id')->nullable();
        });
    }
    if (! Builder::hasGlobalMacro('ofType')) {
        Builder::macro('ofType', function (string $type) {
            /** @var Builder $this */
            return $this->where('provider', $type);
        });
    }

    $user = AppUser::factory()->create();
    $team = OrgTeam::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $this->actingAs($user);

    AppConnectedAccount::creating(function (AppConnectedAccount $a): void {
        $a->token ??= 'default-token';
        $a->provider_id ??= 'default-provider-id';
    });

    // OAuthManager
    $manager = new OAuthManager();
    expect($manager->getSupportedProviders())->toContain('google', 'mailchimp', 'twitter', 'zoom')
        ->and($manager->isProviderSupported('mailchimp'))->toBeTrue()
        ->and($manager->isProviderSupported('nope'))->toBeFalse();

    expect(fn () => $manager->getAuthorizationUrl('nope'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->exchangeCodeForToken('nope', 'code'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->getAuthorizationUrl('google'))->toThrow(RuntimeException::class);
    expect(fn () => $manager->exchangeCodeForToken('google', 'code'))->toThrow(RuntimeException::class);

    foreach (['google', 'mailchimp', 'twitter', 'stripe'] as $p) {
        OAuthConfiguration::create(['service_name' => $p, 'client_id' => 'cid-'.$p, 'client_secret' => 'csec-'.$p, 'is_active' => true]);
    }

    expect($manager->getAuthorizationUrl('google', ['extra']))->toContain('access_type=offline', 'prompt=consent')
        ->and($manager->getAuthorizationUrl('mailchimp'))->not->toContain('scope=')
        ->and($manager->getAuthorizationUrl('twitter'))->toContain('code_challenge=challenge')
        ->and($manager->getAuthorizationUrl('stripe'))->toContain('read_write');

    $mgr = foundationOAuthManagerWithResponses([
        new Psr7Response(200, [], json_encode(['access_token' => 'g-tok', 'expires_in' => 3600])),
        new Psr7Response(200, [], json_encode(['access_token' => 'mc-tok'])),
        new Psr7Response(200, [], json_encode(['user_id' => 'mc-1', 'accountname' => 'MC Co', 'login' => ['email' => 'mc@example.com']])),
        new Psr7Response(200, [], json_encode(['access_token' => 'mc-tok-2'])),
        new RuntimeException('metadata down'),
        new RuntimeException('token endpoint down'),
    ]);

    expect($mgr->exchangeCodeForToken('google', 'code-1')['access_token'])->toBe('g-tok');
    $mcData = $mgr->exchangeCodeForToken('mailchimp', 'code-2');
    expect($mcData['metadata']['user_id'])->toBe('mc-1');
    expect($mgr->exchangeCodeForToken('mailchimp', 'code-3')['metadata'])->toBe([]);
    expect(fn () => $mgr->exchangeCodeForToken('google', 'code-4'))->toThrow(RuntimeException::class);

    $savedMc = AppConnectedAccount::unguarded(fn () => $mgr->saveConnectedAccount('mailchimp', $mcData, $user->id));
    expect($savedMc->provider)->toBe('mailchimp');
    $savedGoogle = AppConnectedAccount::unguarded(fn () => $mgr->saveConnectedAccount('google', ['access_token' => 'g-tok', 'provider_id' => 'g-id']));
    expect($savedGoogle->provider)->toBe('google');

    expect($mgr->needsRefresh(new AppConnectedAccount(['expires_at' => null])))->toBeFalse()
        ->and($mgr->needsRefresh(new AppConnectedAccount(['expires_at' => Carbon::now()->addHour()])))->toBeFalse()
        ->and($mgr->needsRefresh(new AppConnectedAccount(['expires_at' => Carbon::now()->addMinutes(2)])))->toBeTrue();

    $noRef = AppConnectedAccount::unguarded(fn () => AppConnectedAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'p-noref', 'token' => 't1']));
    expect($mgr->refreshToken($noRef))->toBeFalse();

    $badProv = new AppConnectedAccount();
    $badProv->forceFill(['provider' => 'nope', 'refresh_token' => 'r1']);
    expect(fn () => $mgr->refreshToken($badProv))->toThrow(InvalidArgumentException::class);

    $noCfg = new AppConnectedAccount();
    $noCfg->forceFill(['provider' => 'zoom', 'refresh_token' => 'r1']);
    expect(fn () => $mgr->refreshToken($noCfg))->toThrow(RuntimeException::class);

    $refMgr = foundationOAuthManagerWithResponses([
        new Psr7Response(200, [], json_encode(['access_token' => 'new-tok', 'refresh_token' => 'new-ref', 'expires_in' => 1800])),
        new Psr7Response(200, [], json_encode(['access_token' => 'new-tok-2'])),
        new RuntimeException('refresh fail'),
    ]);
    $acc = AppConnectedAccount::unguarded(fn () => AppConnectedAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'p-ref', 'token' => 'old-tok', 'refresh_token' => 'old-ref']));
    expect(AppConnectedAccount::unguarded(fn () => $refMgr->refreshToken($acc)))->toBeTrue()
        ->and(AppConnectedAccount::unguarded(fn () => $refMgr->refreshToken($acc->fresh())))->toBeTrue()
        ->and($refMgr->refreshToken($acc->fresh()))->toBeFalse();

    // MessageService
    Email::$created = [];
    $gmail = AppConnectedAccount::unguarded(fn () => AppConnectedAccount::create(['user_id' => $user->id, 'provider' => 'gmail', 'provider_id' => 'g1', 'token' => 'g-tok']));
    $wa = AppConnectedAccount::unguarded(fn () => AppConnectedAccount::create(['user_id' => $user->id, 'provider' => 'whatsapp', 'provider_id' => 'w1', 'token' => 'w-tok']));
    $fb = AppConnectedAccount::unguarded(fn () => AppConnectedAccount::create(['user_id' => $user->id, 'provider' => 'facebook', 'provider_id' => 'f1', 'token' => 'f-tok']));

    $msgSvc = new MessageService(
        new class() extends WhatsAppBusinessService
        {
            public function getUnreadMessages($account): array
            {
                return [['id' => 'wa-1']];
            }
        },
        new class() extends FacebookMessengerService
        {
            public function getUnreadMessages($account): array
            {
                return [['id' => 'fb-1']];
            }
        },
    );
    $gmailSvc = (new ReflectionProperty($msgSvc, 'gmailService'))->getValue($msgSvc);

    $makeHeader = fn (string $n, string $v) => new class($n, $v)
    {
        public function __construct(private string $n, private string $v) {}

        public function getName(): string
        {
            return $this->n;
        }

        public function getValue(): string
        {
            return $this->v;
        }
    };
    $makeMsg = fn (string $id, ?object $payload) => new class($id, $payload)
    {
        public ?int $accountId = null;

        public function __construct(private string $id, private ?object $payload) {}

        public function getId(): string
        {
            return $this->id;
        }

        public function getThreadId(): string
        {
            return 'thread-1';
        }

        public function getPayload(): ?object
        {
            return $this->payload;
        }
    };

    $bodyPayload = new class([$makeHeader('From', 'a@b.com'), $makeHeader('To', 'me@b.com'), $makeHeader('Subject', 'Hi')])
    {
        public function __construct(private array $h) {}

        public function getHeaders(): array
        {
            return $this->h;
        }

        public function getParts(): array
        {
            return [];
        }

        public function getBody(): object
        {
            return new class()
            {
                public function getData(): string
                {
                    return base64_encode('Hello body');
                }
            };
        }
    };
    $partsPayload = new class([$makeHeader('From', 'a@b.com')])
    {
        public function __construct(private array $h) {}

        public function getHeaders(): array
        {
            return $this->h;
        }

        public function getParts(): array
        {
            return [
                ['mimeType' => 'text/html', 'body' => ['data' => base64_encode('<p>HTML</p>')]],
                ['mimeType' => 'text/plain', 'body' => ['data' => base64_encode('Part body')]],
            ];
        }

        public function getBody(): ?object
        {
            return null;
        }
    };
    $emptyPayload = new class([])
    {
        public function __construct(private array $h) {}

        public function getHeaders(): array
        {
            return $this->h;
        }

        public function getParts(): array
        {
            return [];
        }

        public function getBody(): ?object
        {
            return null;
        }
    };

    $gmailSvc->users_messages->listResult = [$makeMsg('m1', $bodyPayload)];
    expect($msgSvc->getUnreadMessages()['email'])->toHaveCount(1);

    $gmailSvc->users_messages->getResult = $makeMsg('m1', $bodyPayload);
    $msgSvc->getMessage('m1', 'email', $gmail->id);
    $gmailSvc->users_messages->getResult = $makeMsg('m2', $partsPayload);
    $msgSvc->getMessage('m2', 'email', $gmail->id);
    $gmailSvc->users_messages->getResult = $makeMsg('m3', $emptyPayload);
    $msgSvc->getMessage('m3', 'email', $gmail->id);

    expect((new ReflectionMethod($msgSvc, 'getEmailContent'))->invoke($msgSvc, $makeMsg('m4', null)))->toBe('')
        ->and($msgSvc->getMessage('w1', 'whatsapp', $wa->id)['channel'])->toBe('whatsapp')
        ->and($msgSvc->getMessage('f1', 'facebook', $fb->id)['channel'])->toBe('facebook');
    expect(fn () => $msgSvc->getMessage('x', 'sms', $gmail->id))->toThrow(InvalidArgumentException::class);

    $gmailSvc->users_messages->getResult = $makeMsg('m1', $bodyPayload);
    $gmailSvc->users_messages->sendResult = $makeMsg('m-sent', $bodyPayload);
    $msgSvc->sendReply('m1', 'Thanks!', 'email', $gmail->id);
    expect($msgSvc->sendReply('w1', 'Yo', 'whatsapp', $wa->id)['channel'])->toBe('whatsapp')
        ->and($msgSvc->sendReply('f1', 'Yo', 'facebook', $fb->id)['channel'])->toBe('facebook');
    expect(fn () => $msgSvc->sendReply('x', 'Yo', 'sms', $gmail->id))->toThrow(InvalidArgumentException::class);

    // AccountingIntegrationController
    $acctCtrl = new AccountingIntegrationController(new AccountingService(new QuickBooksService(), new XeroService()));
    $connectReq = Request::create('/accounting/connect', 'POST', ['platform' => 'quickbooks', 'credentials' => ['token' => 'abc']]);
    expect($acctCtrl->connect($connectReq)->getStatusCode())->toBe(200);
    $integration = AccountingIntegration::firstOrFail();
    expect($acctCtrl->status($integration)->getData(true)['connected'])->toBeTrue();
    expect($acctCtrl->disconnect($integration)->getStatusCode())->toBe(200);

    $failSvc = Mockery::mock(AccountingService::class);
    $failSvc->shouldReceive('connectPlatform')->andThrow(new RuntimeException('Bad creds'));
    expect((new AccountingIntegrationController($failSvc))->connect($connectReq)->getStatusCode())->toBe(400);

    // OAuthController
    OAuthConfiguration::query()->delete();
    AppConnectedAccount::creating(function (AppConnectedAccount $a): void {
        $a->token ??= 'default-token';
        $a->provider_id ??= 'default-provider-id';
    });

    $oauthCtrl = new OAuthController();
    expect($oauthCtrl->redirect('google'))->toBeInstanceOf(RedirectResponse::class)
        ->and($oauthCtrl->callback('google'))->toBeInstanceOf(RedirectResponse::class);

    foreach (['google', 'facebook', 'linkedin-openid', 'zoom'] as $p) {
        OAuthConfiguration::create(['service_name' => $p, 'client_id' => 'cid-'.$p, 'client_secret' => 'csec-'.$p, 'is_active' => true]);
    }

    $gProv = Mockery::mock();
    $gProv->shouldReceive('scopes')->once()->andReturnSelf();
    $gProv->shouldReceive('with')->once()->with(['access_type' => 'offline', 'prompt' => 'consent'])->andReturnSelf();
    $gProv->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.com/auth'));
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($gProv);
    expect($oauthCtrl->redirect('google')->getTargetUrl())->toBe('https://accounts.google.com/auth');

    $zProv = Mockery::mock();
    $zProv->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://zoom.us/oauth'));
    Socialite::shouldReceive('driver')->with('zoom')->once()->andReturn($zProv);
    expect($oauthCtrl->redirect('zoom')->getTargetUrl())->toBe('https://zoom.us/oauth');

    $sUser = new SocialiteUser();
    $sUser->name = 'Jane';
    $sUser->nickname = 'jane';
    $sUser->email = 'jane@example.com';
    $sUser->avatar = 'https://example.com/a.png';
    $sUser->token = 'tok-1';
    $sUser->refreshToken = 'ref-1';
    $sUser->expiresIn = 3600;

    if (! DbSchema::hasColumn('connected_accounts', 'token_secret')) {
        DbSchema::table('connected_accounts', function (Blueprint $table): void {
            $table->string('token_secret')->nullable();
        });
    }
    if (! DbSchema::hasColumn('connected_accounts', 'metadata')) {
        DbSchema::table('connected_accounts', function (Blueprint $table): void {
            $table->text('metadata')->nullable();
        });
    }
    AppConnectedAccount::saving(function (AppConnectedAccount $a): void {
        if (is_array($a->getAttribute('metadata'))) {
            $a->setAttribute('metadata', json_encode($a->getAttribute('metadata')));
        }
    });

    foreach (['facebook', 'linkedin-openid', 'google'] as $idx => $p) {
        $sUser->id = 'prov-'.$idx;
        $drv = Mockery::mock();
        $drv->shouldReceive('user')->times(3)->andReturn($sUser);
        Socialite::shouldReceive('driver')->with($p)->times(3)->andReturn($drv);

        Http::swap(new Factory());
        Http::fake(fn () => Http::response(['data' => [['id' => 'p1']], 'elements' => [['id' => 'o1']], 'items' => [['id' => 'c1']]], 200));
        expect(AppConnectedAccount::unguarded(fn () => $oauthCtrl->callback($p)))->toBeInstanceOf(RedirectResponse::class);

        Http::swap(new Factory());
        Http::fake(fn () => Http::response(['error' => 'failed'], 500));
        expect(AppConnectedAccount::unguarded(fn () => $oauthCtrl->callback($p)))->toBeInstanceOf(RedirectResponse::class);

        Http::swap(new Factory());
        Http::fake(fn () => throw new RuntimeException('network error'));
        expect(AppConnectedAccount::unguarded(fn () => $oauthCtrl->callback($p)))->toBeInstanceOf(RedirectResponse::class);
    }

    $failDrv = Mockery::mock();
    $failDrv->shouldReceive('user')->once()->andThrow(new RuntimeException('oauth error'));
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($failDrv);
    expect($oauthCtrl->callback('google'))->toBeInstanceOf(RedirectResponse::class);

    // OAuthConfigurationController
    OAuthConfiguration::query()->delete();
    $platform = new PlatformConfiguration();
    $platform->currentProvider = 'direct';
    $platform->credentials = [
        'google_client_id' => 'g-id',
        'google_client_secret' => 'g-sec',
        'facebook_client_id' => 'f-id',
        'facebook_client_secret' => 'f-sec',
        'microsoft_client_id' => 'm-id',
        'microsoft_client_secret' => 'm-sec',
    ];
    $this->app->instance(PlatformConfiguration::class, $platform);

    $workspaceAccess = new WorkspaceAccess();
    $workspaceAccess->allowed = true;
    $this->app->instance(WorkspaceAccess::class, $workspaceAccess);

    $cfgCtrl = new OAuthConfigurationController();

    $tmpViews = sys_get_temp_dir().'/host_integrations_views_'.uniqid();
    @mkdir($tmpViews.'/oauth/configurations', 0777, true);
    file_put_contents($tmpViews.'/oauth/configurations/index.blade.php', 'index');
    file_put_contents($tmpViews.'/oauth/configurations/create.blade.php', 'create');
    View::addLocation($tmpViews);

    expect($cfgCtrl->index()->name())->toBe('oauth.configurations.index')
        ->and($cfgCtrl->create()->name())->toBe('oauth.configurations.create');

    OAuthConfiguration::creating(function (OAuthConfiguration $m): void {
        $m->client_id ??= 'default-cid';
        $m->client_secret ??= 'default-csec';
    });

    $workspaceAccess->allowed = false;
    $storeCfgReq = Request::create('/oauth/configurations', 'POST', [
        'service_name' => 'google',
        'account_name' => 'Primary Google',
        'additional_settings' => ['region' => 'eu'],
    ]);
    expect(fn () => $cfgCtrl->store($storeCfgReq))->toThrow(HttpException::class);

    $workspaceAccess->allowed = true;
    expect($cfgCtrl->store($storeCfgReq))->toBeInstanceOf(RedirectResponse::class);
    $cfgModel = OAuthConfiguration::withoutGlobalScopes()->firstOrFail();

    $absProv = Mockery::mock(AbstractProvider::class);
    $absProv->shouldReceive('scopes')->once()->andReturnSelf();
    $absProv->shouldReceive('with')->once()->andReturnSelf();
    $absProv->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2'));
    Socialite::shouldReceive('forgetDrivers')->atLeast()->once();
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($absProv);
    expect($cfgCtrl->authenticate('google', $cfgModel->id)->getTargetUrl())->toBe('https://accounts.google.com/o/oauth2');

    $cbDrv = Mockery::mock();
    $cbDrv->shouldReceive('user')->once()->andReturn($sUser);
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($cbDrv);
    expect($cfgCtrl->callback('google'))->toBeInstanceOf(RedirectResponse::class);

    session(['oauth_config_service' => 'other']);
    expect($cfgCtrl->callback('google'))->toBeInstanceOf(RedirectResponse::class);

    session(['oauth_config_id' => $cfgModel->id, 'oauth_config_service' => 'google']);
    $badDrv = Mockery::mock();
    $badDrv->shouldReceive('user')->once()->andReturn(new stdClass());
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($badDrv);
    expect($cfgCtrl->callback('google'))->toBeInstanceOf(RedirectResponse::class);

    $outlookCfg = OAuthConfiguration::unguarded(fn () => OAuthConfiguration::create(['team_id' => $team->id, 'user_id' => $user->id, 'service_name' => 'outlook', 'account_name' => 'Outlook', 'client_id' => 'cid', 'client_secret' => 'csec']));
    $msProv = Mockery::mock();
    $msProv->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://login.microsoftonline.com/auth'));
    Socialite::shouldReceive('driver')->with('microsoft')->once()->andReturn($msProv);
    expect($cfgCtrl->authenticate('outlook', $outlookCfg->id)->getTargetUrl())->toBe('https://login.microsoftonline.com/auth');

    $sUser->expiresIn = null;
    $msCbDrv = Mockery::mock();
    $msCbDrv->shouldReceive('user')->once()->andReturn($sUser);
    Socialite::shouldReceive('driver')->with('microsoft')->once()->andReturn($msCbDrv);
    expect($cfgCtrl->callback('outlook'))->toBeInstanceOf(RedirectResponse::class);

    $igCfg = OAuthConfiguration::unguarded(fn () => OAuthConfiguration::create(['team_id' => $team->id, 'user_id' => $user->id, 'service_name' => 'instagram', 'account_name' => 'IG', 'client_id' => 'cid', 'client_secret' => 'csec']));
    Socialite::shouldReceive('driver')->with('facebook')->once()->andThrow(new RuntimeException('driver fail'));
    expect($cfgCtrl->authenticate('instagram', $igCfg->id))->toBeInstanceOf(RedirectResponse::class);

    $platform->currentProvider = 'zernio';
    expect(fn () => $cfgCtrl->authenticate('instagram', $igCfg->id))->toThrow(HttpException::class);
    $platform->currentProvider = 'direct';

    $otherUser = AppUser::factory()->create();
    $otherCfg = OAuthConfiguration::unguarded(fn () => OAuthConfiguration::create(['team_id' => $team->id, 'user_id' => $otherUser->id, 'service_name' => 'whatsapp', 'account_name' => 'Other WA', 'client_id' => 'cid', 'client_secret' => 'csec']));
    expect(fn () => $cfgCtrl->destroy($otherCfg))->toThrow(HttpException::class);
    expect($cfgCtrl->destroy($igCfg))->toBeInstanceOf(RedirectResponse::class);
});

// =========================================================================
// 8. roles-permissions
// =========================================================================

it('covers roles-permissions provider, models, policy, registry, services, and catalogs', function (): void {
    $provider = $this->app->register(RolesPermissionsServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    config(['permissions' => [
        'actions' => ['view', 'create', 'update', 'delete'],
        'groups' => [
            'core' => ['contact', 'company', 'lead', 'deal', 'opportunity', 'task', 'note', 'message'],
            'advertising' => ['advertising_account', 'campaign', 'ad_set', 'ad', 'marketing_campaign', 'social_media_post', 'dashboard_widget', 'call_setting', 'whatsapp_number'],
            'settings' => ['team_member', 'team_role', 'territory'],
            'logs' => ['audit_log'],
            'staff' => ['knowledge_base_article'],
        ],
        'matrix' => [
            'admin' => [
                'groups' => ['core' => ['view', 'create', 'update', 'delete']],
                'resources' => ['contact' => ['view', 'create']],
            ],
        ],
    ]]);

    expect(RoleEnum::values())->toBe(['super_admin', 'admin', 'manager', 'sales_rep', 'free', 'customer']);
    expect((new CustomRoleModel())->team())->toBeInstanceOf(BelongsTo::class);
    expect(new CustomPermissionModel())->toBeInstanceOf(SpatiePermission::class);

    $user = AppUser::factory()->create();
    $role = SpatieRole::create(['name' => 'policy_target', 'guard_name' => 'web', 'team_id' => null]);
    $policy = new RolePolicy();

    foreach ([
        'roles-permissions.roles.view-any',
        'roles-permissions.roles.view',
        'roles-permissions.roles.create',
        'roles-permissions.roles.update',
        'roles-permissions.roles.delete',
        'roles-permissions.roles.delete-any',
        'roles-permissions.roles.restore',
        'roles-permissions.roles.force-delete',
        'roles-permissions.roles.force-delete-any',
        'roles-permissions.roles.restore-any',
        'roles-permissions.roles.replicate',
        'roles-permissions.roles.reorder',
    ] as $ability) {
        Gate::define($ability, fn () => true);
    }

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $role))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->update($user, $role))->toBeTrue()
        ->and($policy->delete($user, $role))->toBeTrue()
        ->and($policy->deleteAny($user))->toBeTrue()
        ->and($policy->restore($user, $role))->toBeTrue()
        ->and($policy->forceDelete($user, $role))->toBeTrue()
        ->and($policy->forceDeleteAny($user))->toBeTrue()
        ->and($policy->restoreAny($user))->toBeTrue()
        ->and($policy->replicate($user, $role))->toBeTrue()
        ->and($policy->reorder($user))->toBeTrue();

    // PermissionRegistry
    $registry = new PermissionRegistry();
    expect(fn () => $registry->declare('invalid_format', 'billing', 'Invalid'))->toThrow(InvalidArgumentException::class);
    $registry->declare('billing.invoices.view', 'billing', 'View invoices');
    expect(fn () => $registry->declare('billing.invoices.view', 'billing', 'Dup'))->toThrow(InvalidArgumentException::class);
    expect($registry->all())->toHaveKey('billing.invoices.view');

    // AnyTeamRoleLookup
    $team = OrgTeam::factory()->create(['user_id' => $user->id]);
    $auditorRole = SpatieRole::create(['name' => 'auditor', 'guard_name' => 'web', 'team_id' => $team->id]);
    $actor = new class($user) implements PrivilegedActor
    {
        public function __construct(private readonly AppUser $user) {}

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
        ->and($lookup->hasRoleInAnyTeam($actor, 'auditor'))->toBeFalse();

    DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))->insert([
        'role_id' => $auditorRole->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $team->id,
    ]);
    expect($lookup->hasRoleInAnyTeam($actor, 'auditor'))->toBeTrue()
        ->and($lookup->hasRoleInAnyTeam($actor, ['other', 'auditor']))->toBeTrue()
        ->and($lookup->hasRoleInAnyTeam($actor, ['other']))->toBeFalse();

    // BreakGlass & SeparationOfDuty
    $breakGlass = new BreakGlass();
    expect(fn () => $breakGlass->grant('actor-1', 'billing.refunds.approve', 'urgent', new DateTimeImmutable('+1 hour'), false))->toThrow(RuntimeException::class);
    expect(fn () => $breakGlass->grant('actor-1', 'billing.refunds.approve', '   ', new DateTimeImmutable('+1 hour'), true))->toThrow(RuntimeException::class);
    expect(fn () => $breakGlass->grant('actor-1', 'billing.refunds.approve', 'urgent', new DateTimeImmutable('-1 minute'), true))->toThrow(RuntimeException::class);
    $bgId = $breakGlass->grant('actor-1', 'billing.refunds.approve', 'incident-123', new DateTimeImmutable('+1 hour'), true);
    expect($bgId)->toBeGreaterThan(0)
        ->and($breakGlass->active('actor-1', 'billing.refunds.approve'))->toBeTrue()
        ->and($breakGlass->active('actor-2', 'billing.refunds.approve'))->toBeFalse();

    $sod = new SeparationOfDuty();
    expect($sod->permits(1, 1, true))->toBeFalse()
        ->and($sod->permits(1, 2, true))->toBeTrue()
        ->and($sod->permits(1, 1, false))->toBeTrue();

    // PermissionCatalog & RolePermissionCatalog
    PermissionCatalog::sync();
    PermissionCatalog::sync();
    SpatiePermission::findOrCreate('custom_platform_flag', 'web');

    $teamOptions = RolePermissionCatalog::options(platform: false);
    $platformOptions = RolePermissionCatalog::options(platform: true);
    expect($teamOptions['view_contact'])->toBe('Contact · View')
        ->and($teamOptions['create_contact'])->toBe('Contact · Create')
        ->and($teamOptions['update_contact'])->toBe('Contact · Edit')
        ->and($teamOptions['delete_contact'])->toBe('Contact · Delete')
        ->and($platformOptions['custom_platform_flag'])->toBe('Custom Platform Flag')
        ->and(RolePermissionCatalog::presets())->toHaveKeys(['sales', 'support', 'social_media', 'advertising'])
        ->and(RolePermissionCatalog::preset('sales'))->toContain('view_contact')
        ->and(RolePermissionCatalog::preset('nonexistent'))->toBe([]);

    // RoleConfigurationService
    $service = new RoleConfigurationService();
    $superAdmin = AppUser::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole(RoleEnum::SuperAdmin->value);

    $owner = AppUser::factory()->create();
    $managedTeam = OrgTeam::factory()->create(['user_id' => $owner->id]);
    $outsider = AppUser::factory()->create();
    $member = AppUser::factory()->create();
    $managedTeam->users()->attach($member, ['role' => 'editor']);

    $teamAdmin = AppUser::factory()->create();
    $managedTeam->users()->attach($teamAdmin, ['role' => 'admin']);
    $globalAdminRole = SpatieRole::whereNull('team_id')->where('guard_name', 'web')->where('name', RoleEnum::Admin->value)->firstOrFail();
    DB::table('model_has_roles')->insert([
        'role_id' => $globalAdminRole->id,
        'model_type' => $teamAdmin->getMorphClass(),
        'model_id' => $teamAdmin->getKey(),
        'team_id' => $managedTeam->id,
    ]);

    expect($service->canManageTeam($superAdmin, $managedTeam))->toBeTrue()
        ->and($service->canManageTeam($owner, $managedTeam))->toBeTrue()
        ->and($service->canManageTeam($outsider, $managedTeam))->toBeFalse()
        ->and($service->canManageTeam($member, $managedTeam))->toBeFalse()
        ->and($service->canManageTeam($teamAdmin, $managedTeam))->toBeTrue();

    $platformRole = $service->save($superAdmin, null, ['name' => '  Billing Auditor  ', 'permissions' => ['view_contact']]);
    expect($platformRole->name)->toBe('Billing Auditor');

    $updatedPlatform = $service->save($superAdmin, null, ['name' => 'Billing Auditor', 'permissions' => ['view_contact', 'create_contact']], $platformRole);
    expect($updatedPlatform->hasPermissionTo('create_contact'))->toBeTrue();

    expect(fn () => $service->save($superAdmin, null, ['name' => 'billing auditor', 'permissions' => []]))->toThrow(ValidationException::class);
    expect(fn () => $service->save($superAdmin, null, ['name' => 'renamed_admin', 'permissions' => []], $globalAdminRole))->toThrow(ValidationException::class);
    expect(fn () => $service->save($superAdmin, null, ['name' => 'Sales Rep', 'permissions' => []]))->toThrow(ValidationException::class);

    $superAdminRole = SpatieRole::whereNull('team_id')->where('guard_name', 'web')->where('name', RoleEnum::SuperAdmin->value)->firstOrFail();
    expect(fn () => $service->save($superAdmin, null, ['name' => RoleEnum::SuperAdmin->value, 'permissions' => []], $superAdminRole))->toThrow(HttpException::class);

    $teamRole = $service->save($owner, $managedTeam, ['name' => 'Account Manager', 'permissions' => ['view_contact']]);
    expect((int) $teamRole->team_id)->toBe($managedTeam->id);

    expect(fn () => $service->save($outsider, $managedTeam, ['name' => 'Rogue', 'permissions' => []]))->toThrow(HttpException::class);
    expect(fn () => $service->delete($superAdmin, null, $globalAdminRole))->toThrow(HttpException::class);

    DB::table('model_has_roles')->insert([
        'role_id' => $teamRole->id,
        'model_type' => $member->getMorphClass(),
        'model_id' => $member->getKey(),
        'team_id' => $managedTeam->id,
    ]);
    expect(fn () => $service->delete($owner, $managedTeam, $teamRole))->toThrow(ValidationException::class);

    DB::table('model_has_roles')->where('role_id', $teamRole->id)->delete();
    $service->delete($owner, $managedTeam, $teamRole);
    $this->assertDatabaseMissing('roles', ['id' => $teamRole->id]);
});

// =========================================================================
// 9. roles-permissions-filament
// =========================================================================

it('covers roles-permissions-filament plugin, provider, builder closures, RoleResource, and pages', function (): void {
    $provider = $this->app->register(RolesPermissionsFilamentServiceProvider::class, force: true);
    expect($provider)->toBeInstanceOf(RolesPermissionsFilamentServiceProvider::class);

    $plugin = RolesPermissionsFilamentPlugin::make();
    expect($plugin->getId())->toBe('filament-shield');
    $panel = Panel::make()->id('roles-host-cov-panel');
    $plugin->register($panel);
    $plugin->boot($panel);

    // Exercise RolePermissionBuilder closures
    $builderListPage = new ListRoles();
    $platformSchema = Schema::make($builderListPage)->components(RolePermissionBuilder::components(platform: true));
    $platformComponents = $platformSchema->getComponents();
    $teamComponents = RolePermissionBuilder::components(platform: false);
    expect($platformComponents)->toHaveCount(2)->and($teamComponents)->toHaveCount(2);

    $section1Children = $platformComponents[0]->getDefaultChildComponents();
    $nameInput = $section1Children[0];
    $presetSelect = $section1Children[1];

    $readOnlyClosure = (new ReflectionProperty($nameInput, 'isReadOnly'))->getValue($nameInput);
    $adminRec = new SpatieRole(['name' => 'admin']);
    expect($readOnlyClosure($adminRec))->toBeTrue()
        ->and($readOnlyClosure(null))->toBeFalse();

    $afterStateUpdated = (new ReflectionProperty($presetSelect, 'afterStateUpdated'))->getValue($presetSelect);
    $setMock = Mockery::mock(Set::class);
    $setMock->shouldReceive('__invoke')->once()->with('permissions', Mockery::type('array'));
    foreach ((array) $afterStateUpdated as $callback) {
        $callback('sales', $setMock);
        $callback(null, $setMock);
    }

    $section2Children = $platformComponents[1]->getDefaultChildComponents();
    $placeholder = $section2Children[0];
    $contentClosure = (new ReflectionProperty($placeholder, 'getConstantStateUsing'))->getValue($placeholder);
    $getMock = Mockery::mock(Get::class);
    $getMock->shouldReceive('__invoke')->with('permissions')->andReturn(['view_contact', 'delete_contact']);
    expect($contentClosure($getMock))->toBe('2 permissions selected; 1 allow deletion.');

    // RoleResource gates, query, form, table, delete action closure, pages
    $globalCustom = SpatieRole::create(['name' => 'custom_auditor', 'guard_name' => 'web', 'team_id' => null]);
    $superAdminRole = SpatieRole::firstOrCreate(['name' => RoleEnum::SuperAdmin->value, 'guard_name' => 'web', 'team_id' => null]);
    $teamScopedRole = SpatieRole::create(['name' => 'team_editor', 'guard_name' => 'web', 'team_id' => 99]);

    $this->actingAs(foundationMakePrivilegedActor(false));
    expect(RoleResource::canAccess())->toBeFalse()
        ->and(RoleResource::canViewAny())->toBeFalse()
        ->and(RoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeFalse()
        ->and(RoleResource::canCreate())->toBeFalse()
        ->and(RoleResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(RoleResource::canView($globalCustom))->toBeFalse()
        ->and(RoleResource::getViewAuthorizationResponse($globalCustom)->allowed())->toBeFalse()
        ->and(RoleResource::canEdit($globalCustom))->toBeFalse()
        ->and(RoleResource::getEditAuthorizationResponse($globalCustom)->allowed())->toBeFalse()
        ->and(RoleResource::canDelete($globalCustom))->toBeFalse()
        ->and(RoleResource::getDeleteAuthorizationResponse($globalCustom)->allowed())->toBeFalse()
        ->and(RoleResource::canDeleteAny())->toBeFalse()
        ->and(RoleResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse();

    $superAdmin = AppUser::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole($superAdminRole);
    $this->actingAs($superAdmin);
    expect(RoleResource::canAccess())->toBeTrue()
        ->and(RoleResource::canViewAny())->toBeTrue()
        ->and(RoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(RoleResource::canCreate())->toBeTrue()
        ->and(RoleResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(RoleResource::canView($globalCustom))->toBeTrue()
        ->and(RoleResource::canView($teamScopedRole))->toBeFalse()
        ->and(RoleResource::getViewAuthorizationResponse($globalCustom)->allowed())->toBeTrue()
        ->and(RoleResource::canEdit($globalCustom))->toBeTrue()
        ->and(RoleResource::canEdit($superAdminRole))->toBeFalse()
        ->and(RoleResource::getEditAuthorizationResponse($globalCustom)->allowed())->toBeTrue()
        ->and(RoleResource::canDelete($globalCustom))->toBeTrue()
        ->and(RoleResource::canDelete($superAdminRole))->toBeFalse()
        ->and(RoleResource::getDeleteAuthorizationResponse($globalCustom)->allowed())->toBeTrue();

    $queryNames = RoleResource::getEloquentQuery()->pluck('name')->all();
    expect($queryNames)->toContain('custom_auditor')->not->toContain('team_editor')
        ->and(array_keys(RoleResource::getPages()))->toBe(['index', 'create', 'edit']);

    $listPage = new ListRoles();
    expect($listPage->getSubheading())->toContain('Global definitions apply across teams');
    $headerActions = Closure::bind(fn (): array => $this->getHeaderActions(), $listPage, ListRoles::class)();
    expect($headerActions)->toHaveCount(1)->and($headerActions[0])->toBeInstanceOf(CreateAction::class);

    expect(RoleResource::form(Schema::make($listPage))->getComponents())->toHaveCount(2);

    $roleTableLivewire = Mockery::mock(HasTable::class);
    $table = RoleResource::table(Table::make($roleTableLivewire));
    $roleTableLivewire->shouldReceive('getTable')->andReturn($table);
    $roleTableLivewire->shouldReceive('getTableRecordKey')->andReturnUsing(fn (Model $r): string => (string) $r->getKey());
    $tableCols = $table->getColumns();
    expect($tableCols)->toHaveCount(4);
    expect($tableCols['scope']->record($globalCustom)->getState())->toBe('Global');

    $recordActions = $table->getRecordActions();
    foreach ($recordActions as $action) {
        if ($action instanceof DeleteAction) {
            $usingClosure = (new ReflectionProperty($action, 'using'))->getValue($action);
            expect($usingClosure($globalCustom))->toBeTrue();
        }
    }

    // CreateRole & EditRole pages using real RoleConfigurationService
    $createPage = new CreateRole();
    $createdRole = Closure::bind(fn (array $d) => $this->handleRecordCreation($d), $createPage, CreateRole::class)(['name' => 'finance_reviewer', 'permissions' => []]);
    expect($createdRole->name)->toBe('finance_reviewer');
    expect(fn () => Closure::bind(fn (array $d) => $this->handleRecordCreation($d), $createPage, CreateRole::class)(['name' => 'finance_reviewer', 'permissions' => []]))->toThrow(ValidationException::class);

    $editPage = new EditRole();
    $editPage->record = $createdRole;
    expect(Closure::bind(fn (array $d): array => $this->mutateFormDataBeforeFill($d), $editPage, EditRole::class)(['name' => 'finance_reviewer'])['permissions'])->toBeArray();
    $updatedRole = Closure::bind(fn ($r, array $d) => $this->handleRecordUpdate($r, $d), $editPage, EditRole::class)($createdRole, ['name' => 'finance_reviewer_v2', 'permissions' => []]);
    expect($updatedRole->name)->toBe('finance_reviewer_v2');
    expect(fn () => Closure::bind(fn ($r, array $d) => $this->handleRecordUpdate($r, $d), $editPage, EditRole::class)($updatedRole, ['name' => '', 'permissions' => []]))->toThrow(ValidationException::class);

    auth()->logout();
    expect(fn () => Closure::bind(fn (array $d) => $this->handleRecordCreation($d), $createPage, CreateRole::class)(['name' => 'x']))->toThrow(HttpException::class);
    expect(fn () => Closure::bind(fn ($r, array $d) => $this->handleRecordUpdate($r, $d), $editPage, EditRole::class)($createdRole, ['name' => 'x']))->toThrow(HttpException::class);
});

// =========================================================================
// 10. analytics-google & analytics-meta
// =========================================================================

it('covers analytics-google and analytics-meta service providers, mappers, normalizers, and destinations', function (): void {
    // Google
    $gEvent = new AnalyticsEvent(
        'evt-google-1',
        'invoice_paid',
        '42',
        new DateTimeImmutable('@1700000000'),
        'billing',
        null,
        null,
        null,
        'en',
        'GBP',
        'analytics',
        ['amount' => 250],
    );

    $mapper = new GoogleEventMapper();
    $mapped = $mapper->map($gEvent);
    expect($mapped['name'])->toBe('invoice_paid')
        ->and($mapped['params']['currency'])->toBe('GBP')
        ->and($mapped['params']['engagement_time_msec'])->toBe(1);

    $tooLong = new AnalyticsEvent('evt-google-2', str_repeat('a', 41), '42', new DateTimeImmutable(), 'billing', null, null, null, null, null, 'analytics');
    expect(fn () => $mapper->map($tooLong))->toThrow(InvalidArgumentException::class, 'exceeds 40 characters');

    $gCaptured = [];
    $gTransport = new class($gCaptured) implements GoogleTransport
    {
        public function __construct(public array &$captured) {}

        public function send(array $payload): array
        {
            $this->captured[] = $payload;

            return ['status' => 'sent'];
        }
    };

    $gDest = new GoogleDestination($mapper, $gTransport);
    expect($gDest->name())->toBe('google')
        ->and($gDest->deliver($gEvent))->toBe(['status' => 'sent']);

    $gProvider = $this->app->register(AnalyticsGoogleServiceProvider::class, force: true);
    app()->offsetUnset(GoogleTransport::class);
    $gProvider->boot();

    $registered = [];
    $registry = new class($registered) implements AnalyticsDestinationRegistry
    {
        public function __construct(public array &$registered) {}

        public function register(AnalyticsDestination $destination): void
        {
            $this->registered[$destination->name()] = $destination;
        }

        public function get(string $name): AnalyticsDestination
        {
            return $this->registered[$name];
        }

        public function all(): array
        {
            return $this->registered;
        }
    };
    app()->instance(AnalyticsDestinationRegistry::class, $registry);
    app()->instance(GoogleTransport::class, $gTransport);
    $gProvider->boot();
    expect($registered['google'])->toBeInstanceOf(GoogleDestination::class);

    // Meta
    $normalizer = new MetaCustomerNormalizer();
    expect($normalizer->email('  User@Example.COM '))->toBe(hash('sha256', 'user@example.com'))
        ->and($normalizer->phone('+44 (0) 20-7946-0958'))->toBe(hash('sha256', '4402079460958'))
        ->and($normalizer->externalId('  cust-99  ', 'secret-salt'))->toBe(hash_hmac('sha256', 'cust-99', 'secret-salt'));

    $mEvent = new AnalyticsEvent('evt-meta-1', 'Purchase', '10', new DateTimeImmutable('@1700000100'), 'checkout', null, null, null, 'en', 'USD', 'analytics', ['value' => 99.5]);
    $mCaptured = [];
    $mTransport = new class($mCaptured) implements MetaTransport
    {
        public function __construct(public array &$captured) {}

        public function send(array $payload): array
        {
            $this->captured[] = $payload;

            return ['events_received' => 1];
        }
    };

    $mDest = new MetaDestination($mTransport);
    expect($mDest->name())->toBe('meta')
        ->and($mDest->deliver($mEvent))->toBe(['events_received' => 1]);

    $mProvider = $this->app->register(AnalyticsMetaServiceProvider::class, force: true);
    app()->offsetUnset(MetaTransport::class);
    $mProvider->boot();

    app()->instance(MetaTransport::class, $mTransport);
    $mProvider->boot();
    expect($registered['meta'])->toBeInstanceOf(MetaDestination::class);
});

// =========================================================================
// 11. jetstream-bridge, localization-core-livewire, observability, search
// =========================================================================

it('covers jetstream-bridge FortifyServiceProvider and all Fortify/Jetstream actions', function (): void {
    $provider = $this->app->register(FortifyServiceProvider::class, force: true);
    $provider->register();
    $provider->boot();

    expect(Password::default())->toBeInstanceOf(Password::class);

    $loginReq = Request::create('/login', 'POST', ['email' => 'User@Example.Test']);
    $loginReq->server->set('REMOTE_ADDR', '127.0.0.1');
    expect(RateLimiter::limiter('login')($loginReq))->toBeInstanceOf(Limit::class);

    $twoFactorReq = Request::create('/two-factor-challenge', 'POST');
    $twoFactorReq->setLaravelSession(app('session.store'));
    $twoFactorReq->session()->put('login.id', 7);
    expect(RateLimiter::limiter('two-factor')($twoFactorReq))->toBeInstanceOf(Limit::class);

    $passkeyReq = Request::create('/passkeys/assert', 'POST', ['credential' => ['id' => 'cred-abc']]);
    $passkeyReq->setLaravelSession(app('session.store'));
    $passkeyReq->server->set('REMOTE_ADDR', '127.0.0.1');
    expect(RateLimiter::limiter('passkeys')($passkeyReq))->toBeInstanceOf(Limit::class);

    $passkeyNoCred = Request::create('/passkeys/assert', 'POST');
    $passkeyNoCred->setLaravelSession(app('session.store'));
    $passkeyNoCred->server->set('REMOTE_ADDR', '127.0.0.1');
    expect(RateLimiter::limiter('passkeys')($passkeyNoCred))->toBeInstanceOf(Limit::class);

    Event::fake([IdentityEvent::class]);
    $originalUserModel = config('auth.providers.users.model');
    $userModel = new class() extends Authenticatable
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'password'];
    };
    config()->set('auth.providers.users.model', $userModel::class);

    try {
        $closed = new CreateNewUser(new ConfiguredRegistrationPolicy('closed'), new RejectingInvitationValidator(), new IdentifierNormalizer());
        expect(fn () => $closed->create(['name' => 'Blocked', 'email' => 'blocked@example.test', 'password' => 'StrongPass#1234', 'password_confirmation' => 'StrongPass#1234']))
            ->toThrow(ValidationException::class);

        $inviteOnly = new CreateNewUser(new ConfiguredRegistrationPolicy('invitation'), new RejectingInvitationValidator(), new IdentifierNormalizer());
        expect(fn () => $inviteOnly->create(['name' => 'Uninvited', 'email' => 'uninvited@example.test', 'password' => 'StrongPass#1234', 'password_confirmation' => 'StrongPass#1234']))
            ->toThrow(ValidationException::class);

        $open = new CreateNewUser(new ConfiguredRegistrationPolicy('open'), new RejectingInvitationValidator(), new IdentifierNormalizer());
        $created = $open->create(['name' => 'Ada Lovelace', 'email' => '  Ada.Cov@Example.TEST ', 'password' => 'StrongPass#1234', 'password_confirmation' => 'StrongPass#1234']);
        expect($created->email)->toBe('ada.cov@example.test');
    } finally {
        config()->set('auth.providers.users.model', $originalUserModel);
    }

    $user = $userModel::create(['name' => 'Grace Hopper', 'email' => 'grace.cov@example.test', 'password' => Hash::make('InitialPass#1234')]);
    (new ResetUserPassword())->reset($user, ['password' => 'ResetPass#5678', 'password_confirmation' => 'ResetPass#5678']);
    expect(Hash::check('ResetPass#5678', $user->fresh()->password))->toBeTrue();

    $this->actingAs($user->fresh(), 'web');
    (new UpdateUserPassword())->update($user->fresh(), ['current_password' => 'ResetPass#5678', 'password' => 'ChangedPass#9012', 'password_confirmation' => 'ChangedPass#9012']);
    expect(Hash::check('ChangedPass#9012', $user->fresh()->password))->toBeTrue();

    $profileAction = new UpdateUserProfileInformation(new IdentifierNormalizer());
    $profileAction->update($user->fresh(), ['name' => 'Rear Admiral Grace', 'email' => 'grace.cov@example.test']);
    expect($user->fresh()->name)->toBe('Rear Admiral Grace');

    $verifiable = new class() extends Authenticatable implements MustVerifyEmail
    {
        protected $table = 'users';

        protected $fillable = ['name', 'email', 'email_verified_at', 'password'];

        public bool $notified = false;

        public function sendEmailVerificationNotification(): void
        {
            $this->notified = true;
        }
    };
    $verifiable = $verifiable::create(['name' => 'Katherine', 'email' => 'katherine.cov@example.test', 'email_verified_at' => now(), 'password' => Hash::make('SecretPass#1234')]);
    $profileAction->update($verifiable, ['name' => 'Katherine Johnson', 'email' => 'KJ.Cov@Example.TEST']);
    expect($verifiable->notified)->toBeTrue()->and($verifiable->fresh()->email)->toBe('kj.cov@example.test');

    $deletedTeams = [];
    $deletesTeams = new class($deletedTeams) implements DeletesTeams
    {
        public function __construct(public array &$deletedTeams) {}

        public function delete(mixed $team): void
        {
            $this->deletedTeams[] = $team;
        }
    };
    $deleteAction = new DeleteUser($deletesTeams);
    expect(fn () => $deleteAction->delete(new stdClass()))->toThrow(InvalidArgumentException::class);

    $log = [];
    $makeDeletable = function (string $tag) use (&$log): object {
        return new class($tag, $log)
        {
            public function __construct(private string $tag, private array &$log) {}

            public function delete(): void
            {
                $this->log[] = $this->tag;
            }
        };
    };
    $delUser = new class($makeDeletable('account'), $makeDeletable('token'), $log) extends Authenticatable
    {
        public array $log;

        public Collection $ownedTeams;

        public Collection $connectedAccounts;

        public Collection $tokens;

        public function __construct(?object $account = null, ?object $token = null, array &$log = [])
        {
            parent::__construct();
            $this->log = &$log;
            $this->ownedTeams = collect(['owned-team-1']);
            $this->connectedAccounts = collect($account ? [$account] : []);
            $this->tokens = collect($token ? [$token] : []);
        }

        public function teams(): object
        {
            return new class($this->log)
            {
                public function __construct(private array &$log) {}

                public function detach(): void
                {
                    $this->log[] = 'teams_detached';
                }
            };
        }

        public function deleteProfilePhoto(): void
        {
            $this->log[] = 'photo_deleted';
        }

        public function delete(): bool
        {
            $this->log[] = 'user_deleted';

            return true;
        }
    };
    $deleteAction->delete($delUser);
    expect($deletedTeams)->toBe(['owned-team-1'])
        ->and($log)->toBe(['teams_detached', 'photo_deleted', 'account', 'token', 'user_deleted']);
});

it('covers localization-core-livewire provider and LanguageSwitcher', function (): void {
    $provider = $this->app->register(LocalizationLivewireServiceProvider::class, force: true);
    $provider->boot();

    config()->set('app.supported_locales', ['en' => 'English', 'es' => 'Español', 'fr' => 'Français']);
    App::setLocale('en');
    Session::flush();

    Livewire::test(LanguageSwitcher::class)
        ->assertSet('currentLocale', 'en')
        ->call('switchLanguage', 'xx')
        ->assertNoRedirect();
    expect(Session::get('locale'))->toBeNull();

    Livewire::withHeaders(['Referer' => 'https://evil.example/phish'])
        ->test(LanguageSwitcher::class)
        ->call('switchLanguage', 'es')
        ->assertSet('currentLocale', 'es')
        ->assertRedirect('/');

    $user = new class() extends Authenticatable
    {
        public ?string $updatedLocale = null;

        public function update(array $attributes = [], array $options = []): bool
        {
            $this->updatedLocale = isset($attributes['locale']) ? (string) $attributes['locale'] : null;

            return true;
        }
    };
    $this->actingAs($user);
    $safeUrl = url('/');

    Livewire::withHeaders(['Referer' => $safeUrl])
        ->test(LanguageSwitcher::class)
        ->call('switchLanguage', 'fr')
        ->assertSet('currentLocale', 'fr')
        ->assertRedirect($safeUrl);

    expect(Session::get('locale'))->toBe('fr')->and($user->updatedLocale)->toBe('fr');
});

it('covers observability providers, gates, Telescope filtering, middleware, metrics, redactor, and SloRegistry', function (): void {
    $provider = $this->app->register(ObservabilityServiceProvider::class, force: true);
    $provider->boot();

    $horizon = new HorizonDashboardServiceProvider($this->app);
    $horizon->boot();

    $admin = new class() extends Authenticatable implements ObservabilityActor
    {
        public function isAdmin(): bool
        {
            return true;
        }

        public function hasRole(): bool
        {
            return false;
        }
    };
    $member = new class() extends Authenticatable implements ObservabilityActor
    {
        public function isAdmin(): bool
        {
            return false;
        }

        public function hasRole(): bool
        {
            return false;
        }
    };

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewTelescope'))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewTelescope'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewPulse'))->toBeFalse();

    $origEnv = $this->app['env'];
    $origFilters = Telescope::$filterUsing;
    try {
        $this->app['env'] = 'production';
        Telescope::$filterUsing = [];
        $telescope = new TelescopeDashboardServiceProvider($this->app);
        $telescope->register();

        $normalEntry = IncomingEntry::make(['type' => 'request']);
        $filter = end(Telescope::$filterUsing);
        expect($filter($normalEntry))->toBeFalse();

        $this->app['env'] = 'local';
        Telescope::$filterUsing = [];
        $localTelescope = new TelescopeDashboardServiceProvider($this->app);
        $localTelescope->register();

        $localFilter = end(Telescope::$filterUsing);
        expect($localFilter($normalEntry))->toBeTrue();
    } finally {
        $this->app['env'] = $origEnv;
        Telescope::$filterUsing = $origFilters;
    }

    $middleware = new CorrelationId();
    $validUuid = '550e8400-e29b-41d4-a716-446655440000';
    $res1 = $middleware->handle(Request::create('/ping', 'GET', [], [], [], ['HTTP_X_CORRELATION_ID' => $validUuid]), static fn (): SymfonyResponse => new SymfonyResponse('ok'));
    expect($res1->headers->get('X-Correlation-ID'))->toBe($validUuid);

    $res2 = $middleware->handle(Request::create('/ping', 'GET', [], [], [], ['HTTP_X_CORRELATION_ID' => 'not-a-uuid']), static fn (): SymfonyResponse => new SymfonyResponse('ok'));
    expect($res2->headers->get('X-Correlation-ID'))->not->toBe('not-a-uuid');

    $metrics = app(Metrics::class);
    expect($metrics)->toBeInstanceOf(NullMetrics::class);
    $metrics->increment('requests.total', 1, ['env' => 'test']);
    $metrics->observe('requests.duration', 12.5, ['route' => 'home']);

    $redactor = new Redactor();
    $redacted = $redactor->redact(['user' => 'alice', 'password' => 'secret123', 'nested' => ['api_token' => 'tok_abc', 'safe' => 'ok']]);
    expect($redacted['password'])->toBe('[REDACTED]')->and($redacted['nested']['api_token'])->toBe('[REDACTED]');

    $slo = new SloRegistry();
    $slo->register('api.availability', 0.999, '30d');
    expect($slo->all())->toBe(['api.availability' => ['target' => 0.999, 'window' => '30d']]);
    expect(fn () => $slo->register('api.availability', 0.99, '7d'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $slo->register('bad.zero', 0.0, '7d'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $slo->register('bad.over', 1.5, '7d'))->toThrow(InvalidArgumentException::class);
});

it('covers search provider, registries, LocalSearchIndexer, ReindexCommand, Searchable, and SearchService', function (): void {
    $this->app->instance(IndexableRegistry::class, new IndexableRegistry());
    $this->app->instance(SearcherRegistry::class, new SearcherRegistry());
    $this->app->register(SearchServiceProvider::class, force: true);

    $indexables = new IndexableRegistry();
    $indexables->register('users', AppUser::class);
    expect($indexables->all())->toBe(['users' => AppUser::class]);
    expect(fn () => $indexables->register('users', AppUser::class))->toThrow(InvalidArgumentException::class);

    $searchers = new SearcherRegistry();
    $stubSearcher = static fn (array $filters): LengthAwarePaginator => new LengthAwarePaginator([], 0, 10);
    $searchers->register('docs', $stubSearcher);
    expect($searchers->types())->toBe(['docs'])->and($searchers->all())->toHaveKey('docs');
    expect(fn () => $searchers->register('docs', $stubSearcher))->toThrow(InvalidArgumentException::class);

    $localIndexer = new LocalSearchIndexer();
    $localIndexer->index('docs', new AppUser());
    $localIndexer->remove('docs', 1);
    $localIndexer->flush('docs');

    $registry = new IndexableRegistry();
    $this->app->instance(IndexableRegistry::class, $registry);
    $indexed = [];
    $flushed = [];
    $this->app->instance(SearchIndexer::class, new class($indexed, $flushed) implements SearchIndexer
    {
        public function __construct(public array &$indexed, public array &$flushed) {}

        public function index(string $type, Model $record): void
        {
            $this->indexed[] = [$type, $record->getKey()];
        }

        public function remove(string $type, int|string $id): void {}

        public function flush(string $type): void
        {
            $this->flushed[] = $type;
        }
    });

    $this->artisan('search:reindex')->expectsOutput('No matching indexable search type.')->assertExitCode(1);

    $user = AppUser::factory()->create();
    $registry->register('users', AppUser::class);
    $this->artisan('search:reindex', ['type' => 'nonexistent'])->expectsOutput('No matching indexable search type.')->assertExitCode(1);
    $this->artisan('search:reindex', ['type' => 'users'])->expectsOutput('Reindexed users.')->assertExitCode(0);
    expect($flushed)->toBe(['users'])->and($indexed)->toContain(['users', $user->getKey()]);

    $modelClass = new class() extends Model
    {
        use Searchable;

        protected $table = 'users';

        protected $guarded = [];

        /** @var list<string> */
        protected array $searchable = ['name', 'email'];

        public function scopeRole(Builder $query, string $role): Builder
        {
            return $query->where('name', 'like', '%'.$role.'%');
        }
    };

    AppUser::factory()->create(['name' => 'Admin Alice', 'email' => 'alice@example.com', 'email_verified_at' => now(), 'created_at' => now()->subDays(2)]);
    AppUser::factory()->create(['name' => 'Guest Bob', 'email' => 'bob@example.com', 'email_verified_at' => null, 'created_at' => now()->subDays(10)]);

    config()->set('search.models.user', $modelClass::class);

    $sReg = new SearcherRegistry();
    $service = new SearchService($sReg);
    $sReg->register('users', fn (array $filters): LengthAwarePaginator => $service->searchUsers($filters));
    $sReg->register('other', fn (array $filters): LengthAwarePaginator => new LengthAwarePaginator([], 0, 5));

    $verifiedResult = $service->searchUsers([
        'query' => 'Alice',
        'role' => 'Admin',
        'verified' => true,
        'created_from' => now()->subDays(5)->toDateTimeString(),
        'created_to' => now()->addDay()->toDateTimeString(),
        'order_by' => 'name',
        'order_direction' => 'asc',
        'per_page' => 10,
    ]);
    expect($verifiedResult->total())->toBe(1);

    $unverifiedResult = $service->searchUsers(['verified' => false, 'order_direction' => 'desc', 'per_page' => 5]);
    expect($unverifiedResult->total())->toBe(1);

    expect(array_keys($service->searchAll(['query' => 'Alice'])))->toBe(['users', 'other'])
        ->and(array_keys($service->searchAll(['types' => ['users'], 'query' => 'Alice'])))->toBe(['users']);
});
