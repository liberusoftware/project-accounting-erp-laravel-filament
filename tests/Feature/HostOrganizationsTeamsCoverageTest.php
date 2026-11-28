<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as FoundationAuthUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Organizations\Actions\Jetstream\AddTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\CreateTeam as CreateTeamAction;
use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteTeam;
use Liberu\Foundation\Organizations\Actions\Jetstream\InviteTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\RemoveTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\UpdateTeamName;
use Liberu\Foundation\Organizations\Contracts\OrganizationActor;
use Liberu\Foundation\Organizations\Http\Controllers\TeamInvitationController;
use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Services\TeamManagementService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Liberu\Foundation\Organizations\Support\AccessContext;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamMemberResource;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamMemberResource\Pages\ListTeamMembers;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleLogResource\Pages\ListTeamRoleLogs;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\CreateTeamRole;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\EditTeamRole;
use Liberu\Foundation\OrganizationsFilament\App\Resources\TeamRoleResource\Pages\ListTeamRoles;
use Liberu\Foundation\OrganizationsFilament\Http\Middleware\AssignDefaultTeam;
use Liberu\Foundation\OrganizationsFilament\Http\Middleware\SetFilamentDefaultTenant;
use Liberu\Foundation\OrganizationsFilament\OrganizationsFilamentPlugin;
use Liberu\Foundation\OrganizationsFilament\OrganizationsFilamentServiceProvider;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamBackupResource;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamBackupResource\Pages\ListTeamBackups;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\CreateTeam;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\EditTeam;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\ListTeams;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\CreateTeamSubscription;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\EditTeamSubscription;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamSubscriptionResource\Pages\ListTeamSubscriptions;
use Liberu\Foundation\OrganizationsFilament\Support\FilamentTeamJobNotifier;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpKernel\Exception\HttpException;

function hostOrgUser(array $overrides = []): User
{
    static $seq = 0;
    $seq++;

    return User::forceCreate(array_merge([
        'name' => "Host Org User {$seq}",
        'email' => "host-org-user-{$seq}-".uniqid().'@example.test',
        'password' => bcrypt('Password123!@#'),
    ], $overrides));
}

function hostOrgTeam(User $owner, array $overrides = []): Team
{
    return Team::forceCreate(array_merge([
        'user_id' => $owner->id,
        'name' => 'Host Org Team '.uniqid(),
        'personal_team' => false,
    ], $overrides));
}

function hostOrgInvokeProtected(object $instance, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($instance, $method);

    return $ref->invokeArgs($instance, $args);
}

beforeEach(function () {
    AccessContext::clear();
    Model::preventSilentlyDiscardingAttributes(false);
});

afterEach(function () {
    AccessContext::clear();
    Auth::logout();
    Auth::forgetGuards();
    Filament::setTenant(null);
    config()->set('organizations-teams.audit_log_model', null);
    Model::preventSilentlyDiscardingAttributes(true);
});

it('covers AccessContext across guest, override, restricted roles, territories, and masked roles', function () {
    expect(AccessContext::restrictToOwnerId())->toBeNull()
        ->and(AccessContext::restrictedTerritoryIds())->toBeNull()
        ->and(AccessContext::shouldMaskFields())->toBeFalse();

    AccessContext::restrictTo(42);
    expect(AccessContext::restrictToOwnerId())->toBe(42);
    AccessContext::clear();
    expect(AccessContext::restrictToOwnerId())->toBeNull();

    $user = hostOrgUser();
    $team = hostOrgTeam($user);
    setPermissionsTeamId($team->id);
    Auth::login($user);

    expect(AccessContext::restrictToOwnerId())->toBeNull()
        ->and(AccessContext::restrictedTerritoryIds())->toBeNull()
        ->and(AccessContext::shouldMaskFields())->toBeFalse();

    $salesRole = SpatieRole::firstOrCreate(['name' => Role::SalesRep->value, 'guard_name' => 'web', 'team_id' => null]);
    $user->assignRole($salesRole);

    expect(AccessContext::restrictToOwnerId())->toBe($user->id)
        ->and(AccessContext::shouldMaskFields())->toBeFalse();

    $freeRole = SpatieRole::firstOrCreate(['name' => Role::Free->value, 'guard_name' => 'web', 'team_id' => null]);
    $user->assignRole($freeRole);
    expect(AccessContext::shouldMaskFields())->toBeTrue();

    // Territory-capable user without restricted role returns null
    $unrestrictedTerritoryUser = new class() extends FoundationAuthUser
    {
        public function hasRole(string $role): bool
        {
            return false;
        }

        public function territories(): object
        {
            return new class()
            {
                public function pluck(string $column): Collection
                {
                    return collect([10, 20]);
                }
            };
        }
    };
    Auth::logout();
    Auth::forgetGuards();
    Auth::setUser($unrestrictedTerritoryUser);
    expect(AccessContext::restrictedTerritoryIds())->toBeNull();

    // Territory-capable user with restricted role returns territory IDs
    $restrictedTerritoryUser = new class() extends FoundationAuthUser
    {
        public function hasRole(string $role): bool
        {
            return $role === Role::SalesRep->value;
        }

        public function territories(): object
        {
            return new class()
            {
                public function pluck(string $column): Collection
                {
                    return collect([101, 202]);
                }
            };
        }
    };
    Auth::forgetGuards();
    Auth::setUser($restrictedTerritoryUser);
    expect(AccessContext::restrictedTerritoryIds())->toBe([101, 202]);
});

it('covers Jetstream CreateTeam, UpdateTeamName, and DeleteTeam actions', function () {
    Event::fake([AddingTeam::class]);

    $ownerRecord = hostOrgUser();
    $owner = (new class() extends User
    {
        protected $table = 'users';

        protected string $guard_name = 'web';

        public function getMorphClass(): string
        {
            return User::class;
        }

        public function getForeignKey(): string
        {
            return 'user_id';
        }

        public function ownedTeams(): HasMany
        {
            return $this->hasMany(Team::class, 'user_id');
        }
    })->newFromBuilder($ownerRecord->getAttributes());
    $outsider = hostOrgUser();

    Gate::before(fn ($u, string $ability) => $ability === 'create' && $u->getKey() === $owner->getKey() ? true : null);

    $createAction = new CreateTeamAction();
    $team = $createAction->create($owner, ['name' => 'Alpha Workspace']);

    Event::assertDispatched(AddingTeam::class);
    expect($team->name)->toBe('Alpha Workspace')
        ->and($ownerRecord->fresh()->current_team_id)->toBe($team->id);

    expect(fn () => $createAction->create($owner, ['name' => '']))
        ->toThrow(ValidationException::class);

    $updateAction = new UpdateTeamName();
    $updateAction->update($owner, $team, ['name' => 'Renamed Workspace']);
    expect($team->fresh()->name)->toBe('Renamed Workspace');

    expect(fn () => $updateAction->update($outsider, $team, ['name' => 'Hack']))
        ->toThrow(AuthorizationException::class);

    (new DeleteTeam())->delete($team);
    expect(Team::withArchived()->find($team->id))->toBeNull();
});

it('covers Jetstream AddTeamMember, InviteTeamMember, and RemoveTeamMember actions', function () {
    Mail::fake();
    Event::fake([AddingTeamMember::class, TeamMemberAdded::class, InvitingTeamMember::class, TeamMemberRemoved::class]);

    $owner = hostOrgUser();
    $member = hostOrgUser();
    $invitedUser = hostOrgUser();
    $noRoleUser = hostOrgUser();
    $outsider = hostOrgUser();
    $team = hostOrgTeam($owner);

    $originalRoles = Jetstream::$roles;

    try {
        // Ensure Jetstream roles are registered to exercise the Jetstream::hasRoles() true branch
        Jetstream::role('admin', 'Administrator', ['create', 'read', 'update', 'delete']);
        Jetstream::role('editor', 'Editor', ['read', 'create', 'update']);

        $inviter = new InviteTeamMember();
        $inviter->invite($owner, $team, $invitedUser->email, 'editor');
        Event::assertDispatched(InvitingTeamMember::class);
        Mail::assertSent(TeamInvitationMail::class);

        expect(fn () => $inviter->invite($owner, $team, $invitedUser->email, 'editor'))
            ->toThrow(ValidationException::class);

        $adder = new AddTeamMember();
        $adder->add($owner, $team, $invitedUser->email, 'admin');
        Event::assertDispatched(AddingTeamMember::class);
        Event::assertDispatched(TeamMemberAdded::class);
        expect($team->fresh()->hasUserWithEmail($invitedUser->email))->toBeTrue()
            ->and(TeamInvitation::where('email', $invitedUser->email)->exists())->toBeFalse();

        expect(fn () => $adder->add($owner, $team->fresh(), $invitedUser->email, 'admin'))
            ->toThrow(ValidationException::class);

        expect(fn () => $inviter->invite($owner, $team->fresh(), $invitedUser->email, 'editor'))
            ->toThrow(ValidationException::class);

        $adder->add($owner, $team, $member->email, 'editor');
        expect($team->fresh()->hasUserWithEmail($member->email))->toBeTrue();

        // Exercise Jetstream::hasRoles() === false branch on both InviteTeamMember and AddTeamMember
        Jetstream::$roles = [];
        $inviter->invite($owner, $team, $noRoleUser->email);
        $adder->add($owner, $team, $noRoleUser->email);
        expect($team->fresh()->hasUserWithEmail($noRoleUser->email))->toBeTrue();
    } finally {
        Jetstream::$roles = $originalRoles;
    }

    $remover = new RemoveTeamMember();

    expect(fn () => $remover->remove($outsider, $team, $member))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $remover->remove($owner, $team, $owner))
        ->toThrow(ValidationException::class);

    $remover->remove($owner, $team, $member);
    Event::assertDispatched(TeamMemberRemoved::class);
    expect($team->fresh()->hasUserWithEmail($member->email))->toBeFalse();

    $remover->remove($invitedUser, $team, $invitedUser);
    expect($team->fresh()->hasUserWithEmail($invitedUser->email))->toBeFalse();
});

it('covers TeamInvitationController sendInvitation and acceptInvitation', function () {
    Mail::fake();
    Event::fake([TeamMemberAdded::class]);

    $owner = hostOrgUser();
    $invitee = hostOrgUser(['email' => 'host-invited-ctrl@example.test']);
    $other = hostOrgUser(['email' => 'host-other-ctrl@example.test']);
    $team = hostOrgTeam($owner);

    $controller = new TeamInvitationController();

    $originalRoles = Jetstream::$roles;
    Jetstream::role('editor', 'Editor', ['read', 'create', 'update']);

    try {
        $sendReq = Request::create('/invitations', 'POST', [
            'email' => $invitee->email,
            'role' => 'editor',
            'team_id' => $team->id,
        ]);
        $sendReq->setUserResolver(fn () => $owner);

        $resp = $controller->sendInvitation($sendReq, new InviteTeamMember());
        expect($resp->getStatusCode())->toBe(302);
    } finally {
        Jetstream::$roles = $originalRoles;
    }

    $invitation = TeamInvitation::where('email', $invitee->email)->firstOrFail();

    $guestReq = Request::create('/invitations/accept', 'GET');
    expect(fn () => $controller->acceptInvitation($guestReq, $invitation->id))
        ->toThrow(HttpException::class);

    $wrongUserReq = Request::create('/invitations/accept', 'GET');
    $wrongUserReq->setUserResolver(fn () => $other);
    expect(fn () => $controller->acceptInvitation($wrongUserReq, $invitation->id))
        ->toThrow(HttpException::class);

    $badInv = TeamInvitation::forceCreate([
        'team_id' => $team->id,
        'email' => $other->email,
        'role' => 'super_admin',
    ]);
    expect(fn () => $controller->acceptInvitation($wrongUserReq, $badInv->id))
        ->toThrow(HttpException::class);

    $acceptReq = Request::create('/invitations/accept', 'GET');
    $acceptReq->setUserResolver(fn () => $invitee);
    $acceptResp = $controller->acceptInvitation($acceptReq, $invitation->id);

    expect($acceptResp->getStatusCode())->toBe(302)
        ->and($invitee->fresh()->current_team_id)->toBe($team->id)
        ->and(TeamInvitation::find($invitation->id))->toBeNull();
    Event::assertDispatched(TeamMemberAdded::class);

    $managerInvitee = hostOrgUser(['email' => 'host-mgr-invitee@example.test']);
    $mgrInv = TeamInvitation::forceCreate([
        'team_id' => $team->id,
        'email' => $managerInvitee->email,
        'role' => Role::Manager->value,
    ]);
    $mgrReq = Request::create('/invitations/accept', 'GET');
    $mgrReq->setUserResolver(fn () => $managerInvitee);
    $mgrResp = $controller->acceptInvitation($mgrReq, $mgrInv->id);
    expect($mgrResp->getStatusCode())->toBe(302)
        ->and($managerInvitee->fresh()->current_team_id)->toBe($team->id);
});

it('covers AssignDefaultTeam, SetFilamentDefaultTenant, FilamentTeamJobNotifier, plugin, and service provider', function () {
    $next = fn (Request $req): Response => new Response('ok', 200);
    $req = Request::create('/test', 'GET');

    Filament::setTenant(null);
    $mw = new AssignDefaultTeam();
    expect($mw->handle($req, $next)->getStatusCode())->toBe(200)
        ->and(Filament::getTenant())->toBeNull();

    $userNoTeam = hostOrgUser();
    Auth::login($userNoTeam);
    $mw->handle($req, $next);
    expect(Filament::getTenant())->not->toBeNull()
        ->and($userNoTeam->fresh()->current_team_id)->not->toBeNull();

    Filament::setTenant(null);
    $mw->handle($req, $next);
    expect(Filament::getTenant()?->id)->toBe($userNoTeam->fresh()->current_team_id);

    Filament::setTenant(null);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $defaultTenantMw = new SetFilamentDefaultTenant();
    expect($defaultTenantMw->handle($req, $next)->getStatusCode())->toBe(200)
        ->and($defaultTenantMw->handle($req, $next)->getStatusCode())->toBe(200);

    $notifier = new FilamentTeamJobNotifier();
    $notifier->notify($userNoTeam, 'Done', 'All good', true);
    $notifier->notify($userNoTeam, 'Failed', 'Something broke', false);
    expect($userNoTeam->notifications()->count())->toBe(2);

    $plugin = OrganizationsFilamentPlugin::make();
    $panel = Panel::make()->id('host-org-plugin-test');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBe('liberu-organizations')
        ->and($panel->getResources())->toContain(TeamResource::class);

    (new OrganizationsFilamentServiceProvider(app()))->register();
});

it('covers TeamMemberResource and ListTeamMembers page', function () {
    $owner = hostOrgUser();
    $member = hostOrgUser();
    $extra = hostOrgUser();
    $team = hostOrgTeam($owner);
    $team->users()->attach($member, ['role' => 'member']);

    $customRole = SpatieRole::create(['name' => 'host_fil_custom_role', 'guard_name' => 'web', 'team_id' => $team->id]);

    expect(TeamMemberResource::permissionResource())->toBe('team_member')
        ->and(TeamMemberResource::canViewAny())->toBeFalse()
        ->and(TeamMemberResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamMemberResource::getEloquentQuery()->count())->toBe(0)
        ->and(TeamMemberResource::getPages())->toHaveKey('index');

    Auth::login($owner);
    Filament::setTenant($team);
    setPermissionsTeamId($team->id);
    Gate::before(fn ($u) => $u->is($owner) ? true : null);

    expect(TeamMemberResource::canViewAny())->toBeTrue()
        ->and(TeamMemberResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(TeamMemberResource::getEloquentQuery()->pluck('id')->all())->toContain($owner->id, $member->id)
        ->and(TeamMemberResource::assignableRoles())->toHaveKeys(['manager', 'sales_rep', 'free', 'host_fil_custom_role']);

    $page = new ListTeamMembers();
    $table = TeamMemberResource::table(Table::make($page));
    (new ReflectionProperty($page, 'table'))->setValue($page, $table);

    $roleColumn = $table->getColumns()['team_role'];
    expect($roleColumn->record($member)->getState())->toBe('—');

    $recordActions = collect($table->getRecordActions())->keyBy(fn ($a) => $a->getName());

    $changeRole = $recordActions['changeRole'];
    expect($changeRole->record($owner)->isVisible())->toBeFalse()
        ->and($changeRole->record($member)->isVisible())->toBeTrue();

    $changeRoleSchema = $changeRole->record($member)->getSchema(FilamentSchema::make($page));
    $changeRoleSelect = collect($changeRoleSchema->getComponents())->first(fn ($c) => $c->getName() === 'role');
    expect($changeRoleSelect->getOptions())->toHaveKey('host_fil_custom_role');

    $changeRole->record($member)->evaluate($changeRole->getActionFunction(), [
        'record' => $member,
        'data' => ['role' => Role::Manager->value],
        'service' => new TeamManagementService(),
    ]);
    expect($member->fresh()->hasRole(Role::Manager->value))->toBeTrue();

    $changeRole->record($member)->evaluate($changeRole->getActionFunction(), [
        'record' => $member,
        'data' => ['role' => 'host_fil_custom_role'],
        'service' => new TeamManagementService(),
    ]);
    expect($member->fresh()->hasRole('host_fil_custom_role'))->toBeTrue();

    $bulkActions = collect($table->getToolbarActions())->keyBy(fn ($a) => $a->getName());
    $setRole = $bulkActions['setRole'];
    $setRoleSchema = $setRole->getSchema(FilamentSchema::make($page));
    $setRoleSelect = collect($setRoleSchema->getComponents())->first(fn ($c) => $c->getName() === 'role');
    expect($setRoleSelect->getOptions())->toHaveKey('host_fil_custom_role');

    $setRole->evaluate($setRole->getActionFunction(), [
        'records' => Collection::make([$owner, $member]),
        'data' => ['role' => Role::SalesRep->value],
        'service' => new TeamManagementService(),
    ]);
    expect($member->fresh()->hasRole(Role::SalesRep->value))->toBeTrue();

    $setRole->evaluate($setRole->getActionFunction(), [
        'records' => Collection::make([$member, hostOrgUser()]),
        'data' => ['role' => 'host_fil_custom_role'],
        'service' => new TeamManagementService(),
    ]);
    expect($member->fresh()->hasRole('host_fil_custom_role'))->toBeTrue();

    $removeMember = $recordActions['removeMember'];
    expect($removeMember->record($owner)->isVisible())->toBeFalse()
        ->and($removeMember->record($member)->isVisible())->toBeTrue();

    $removeMember->record($member)->evaluate($removeMember->getActionFunction(), [
        'record' => $member,
        'service' => new TeamManagementService(),
    ]);
    expect($team->users()->whereKey($member->id)->exists())->toBeFalse();

    $headerActions = hostOrgInvokeProtected($page, 'getHeaderActions');
    $addMember = $headerActions[0];

    $addMember->evaluate($addMember->getActionFunction(), [
        'data' => ['email' => $extra->email, 'password' => null, 'role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);
    expect($team->users()->whereKey($extra->id)->exists())->toBeTrue();

    $addMember->evaluate($addMember->getActionFunction(), [
        'data' => ['name' => 'No Pass', 'email' => 'host-nopass@example.test', 'password' => null, 'role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);

    $addMember->evaluate($addMember->getActionFunction(), [
        'data' => ['name' => 'Brand New', 'email' => 'host-brandnew@example.test', 'password' => 'SuperSecret123!@#', 'role' => Role::Manager->value],
        'service' => new TeamManagementService(),
    ]);
    expect(User::where('email', 'host-brandnew@example.test')->exists())->toBeTrue();

    Filament::setTenant(null);
    $owner->forceFill(['current_team_id' => null])->save();
    expect(TeamMemberResource::assignableRoles())->toHaveKeys(['manager', 'sales_rep', 'free']);
    $changeRole->record($member)->evaluate($changeRole->getActionFunction(), [
        'record' => $member,
        'data' => ['role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);
    $setRole->evaluate($setRole->getActionFunction(), [
        'records' => Collection::make([$member]),
        'data' => ['role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);
    $addMember->evaluate($addMember->getActionFunction(), [
        'data' => ['email' => 'host-x@example.test', 'password' => 'SuperSecret123!@#', 'role' => Role::Free->value],
        'service' => new TeamManagementService(),
    ]);
});

it('covers TeamRoleLogResource and ListTeamRoleLogs page', function () {
    $owner = hostOrgUser();
    $team = hostOrgTeam($owner);

    expect(TeamRoleLogResource::getModel())->toBe(Team::class)
        ->and(TeamRoleLogResource::permissionResource())->toBe('team_role_log')
        ->and(TeamRoleLogResource::canAccess())->toBeFalse()
        ->and(TeamRoleLogResource::canViewAny())->toBeFalse()
        ->and(TeamRoleLogResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleLogResource::canCreate())->toBeFalse()
        ->and(TeamRoleLogResource::getCreateAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleLogResource::getPages())->toHaveKey('index');

    $auditModel = new class() extends Model
    {
        protected $table = 'audit_logs';
    };
    config()->set('organizations-teams.audit_log_model', $auditModel::class);

    Auth::login($owner);
    Filament::setTenant($team);

    expect(TeamRoleLogResource::getModel())->toBe($auditModel::class)
        ->and(TeamRoleLogResource::canAccess())->toBeTrue()
        ->and(TeamRoleLogResource::canViewAny())->toBeTrue()
        ->and(TeamRoleLogResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(TeamRoleLogResource::getEloquentQuery()->toSql())->toContain('team_id');

    $page = new ListTeamRoleLogs();
    $table = TeamRoleLogResource::table(Table::make($page));
    expect($table->getColumns())->toHaveKeys(['created_at', 'user.name', 'action', 'description'])
        ->and(hostOrgInvokeProtected($page, 'getHeaderActions'))->toBe([]);
});

it('covers TeamRoleResource and its List, Create, and Edit pages', function () {
    $owner = hostOrgUser();
    $team = hostOrgTeam($owner);
    $customRole = SpatieRole::create(['name' => 'host_team_editor_custom', 'guard_name' => 'web', 'team_id' => $team->id]);
    $systemRole = SpatieRole::firstOrCreate(['name' => Role::Admin->value, 'guard_name' => 'web', 'team_id' => null]);

    expect(TeamRoleResource::permissionResource())->toBe('team_role')
        ->and(TeamRoleResource::team())->toBeNull()
        ->and(TeamRoleResource::canAccess())->toBeFalse()
        ->and(TeamRoleResource::canViewAny())->toBeFalse()
        ->and(TeamRoleResource::getViewAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleResource::canCreate())->toBeFalse()
        ->and(TeamRoleResource::getCreateAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleResource::canView($customRole))->toBeFalse()
        ->and(TeamRoleResource::getViewAuthorizationResponse($customRole)->denied())->toBeTrue()
        ->and(TeamRoleResource::canEdit($customRole))->toBeFalse()
        ->and(TeamRoleResource::getEditAuthorizationResponse($customRole)->denied())->toBeTrue()
        ->and(TeamRoleResource::canDelete($customRole))->toBeFalse()
        ->and(TeamRoleResource::getDeleteAuthorizationResponse($customRole)->denied())->toBeTrue()
        ->and(TeamRoleResource::canDeleteAny())->toBeFalse()
        ->and(TeamRoleResource::getDeleteAnyAuthorizationResponse()->denied())->toBeTrue()
        ->and(TeamRoleResource::getEloquentQuery()->count())->toBe(0)
        ->and(TeamRoleResource::grantablePermissions())->toBeArray()
        ->and(TeamRoleResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    Auth::login($owner);
    Filament::setTenant($team);

    expect(TeamRoleResource::team()?->id)->toBe($team->id)
        ->and(TeamRoleResource::canAccess())->toBeTrue()
        ->and(TeamRoleResource::canViewAny())->toBeTrue()
        ->and(TeamRoleResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(TeamRoleResource::canCreate())->toBeTrue()
        ->and(TeamRoleResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(TeamRoleResource::canView($customRole))->toBeTrue()
        ->and(TeamRoleResource::getViewAuthorizationResponse($customRole)->allowed())->toBeTrue()
        ->and(TeamRoleResource::canEdit($customRole))->toBeTrue()
        ->and(TeamRoleResource::getEditAuthorizationResponse($customRole)->allowed())->toBeTrue()
        ->and(TeamRoleResource::canDelete($customRole))->toBeTrue()
        ->and(TeamRoleResource::getDeleteAuthorizationResponse($customRole)->allowed())->toBeTrue()
        ->and(TeamRoleResource::canDelete($systemRole))->toBeFalse()
        ->and(TeamRoleResource::getEloquentQuery()->pluck('id')->all())->toContain($customRole->id);

    $listPage = new ListTeamRoles();
    expect(hostOrgInvokeProtected($listPage, 'getHeaderActions'))->toHaveCount(1);

    // Exercise fallback branch of TeamRoleResource::form() when RolePermissionBuilder is not yet loaded
    if (! class_exists('Liberu\\Foundation\\RolesPermissionsFilament\\Forms\\RolePermissionBuilder', false)) {
        // Preload classes needed by the fallback branch before temporarily suspending autoloaders
        TextInput::make('preload');
        CheckboxList::make('preload');
        RolePermissionCatalog::options();
        $fallbackSchema = FilamentSchema::make($listPage);

        $autoloaders = spl_autoload_functions();
        foreach ($autoloaders as $loader) {
            spl_autoload_unregister($loader);
        }
        try {
            $fallbackResult = TeamRoleResource::form($fallbackSchema);
        } finally {
            foreach ($autoloaders as $loader) {
                spl_autoload_register($loader);
            }
        }
        expect($fallbackResult->getComponents())->toHaveCount(2);
    }

    // Exercise RolePermissionBuilder branch of TeamRoleResource::form()
    $formSchema = TeamRoleResource::form(FilamentSchema::make($listPage));
    expect($formSchema->getComponents())->not->toBeEmpty();

    $table = TeamRoleResource::table(Table::make($listPage));
    expect($table->getColumns())->toHaveKeys(['name', 'permissions_count', 'updated_at']);

    $createPage = new CreateTeamRole();
    $createdRole = hostOrgInvokeProtected($createPage, 'handleRecordCreation', [[
        'name' => 'Host Support Lead',
        'permissions' => [],
    ]]);
    expect($createdRole)->toBeInstanceOf(SpatieRole::class)
        ->and($createdRole->name)->toBe('Host Support Lead');

    expect(fn () => hostOrgInvokeProtected($createPage, 'handleRecordCreation', [[
        'name' => 'admin',
        'permissions' => [],
    ]]))->toThrow(ValidationException::class);

    $editPage = new EditTeamRole();
    $editPage->record = $createdRole;
    $filled = hostOrgInvokeProtected($editPage, 'mutateFormDataBeforeFill', [['name' => 'Host Support Lead']]);
    expect($filled)->toHaveKey('permissions');

    $updatedRole = hostOrgInvokeProtected($editPage, 'handleRecordUpdate', [$createdRole, [
        'name' => 'Host Support Lead Updated',
        'permissions' => [],
    ]]);
    expect($updatedRole->name)->toBe('Host Support Lead Updated');

    expect(fn () => hostOrgInvokeProtected($editPage, 'handleRecordUpdate', [$createdRole, [
        'name' => 'super_admin',
        'permissions' => [],
    ]]))->toThrow(ValidationException::class);

    $deleteAction = collect($table->getRecordActions())->first(fn ($a) => $a->getName() === 'delete');
    $refUsing = new ReflectionProperty($deleteAction, 'using');
    $usingClosure = $refUsing->getValue($deleteAction);
    expect($usingClosure($updatedRole))->toBeTrue()
        ->and(SpatieRole::find($updatedRole->id))->toBeNull();
});

it('covers TeamBackupResource and ListTeamBackups page', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = hostOrgUser(['email' => 'host-ops-admin@example.test']);
    setPermissionsTeamId(null);
    $admin->assignRole(SpatieRole::firstOrCreate(['name' => Role::SuperAdmin->value, 'guard_name' => 'web', 'team_id' => null]));
    $team = hostOrgTeam($admin);

    expect(TeamBackupResource::isScopedToTenant())->toBeFalse()
        ->and(TeamBackupResource::canAccess())->toBeFalse()
        ->and(TeamBackupResource::getRouteMiddleware(Filament::getPanel('admin')))->toBe([SetFilamentDefaultTenant::class])
        ->and(TeamBackupResource::getPages())->toHaveKey('index');

    // User with isAdmin() method
    Auth::login($admin);
    expect(TeamBackupResource::canAccess())->toBeTrue();

    // User without isAdmin() method falls back to hasRole(Role::SuperAdmin)
    $roleOnlyUser = new class() extends FoundationAuthUser
    {
        public function hasRole(mixed $role): bool
        {
            return $role === Role::SuperAdmin;
        }
    };
    Auth::setUser($roleOnlyUser);
    expect(TeamBackupResource::canAccess())->toBeTrue();
    Auth::login($admin);

    $page = new ListTeamBackups();
    expect(TeamBackupResource::form(FilamentSchema::make($page))->getComponents())->toBe([]);

    $table = TeamBackupResource::table(Table::make($page));
    $columns = $table->getColumns();
    expect($columns)->toHaveKeys(['team.name', 'status', 'size_bytes', 'created_at']);

    $statusCol = $columns['status'];
    foreach (['completed' => 'success', 'failed' => 'danger', 'processing' => 'warning', 'pending' => 'gray'] as $state => $color) {
        expect($statusCol->getColor($state))->toBe($color);
    }

    $sizeCol = $columns['size_bytes'];
    expect($sizeCol->formatState(2048))->toBe('2.0 KB')
        ->and($sizeCol->formatState(null))->toBe('—');

    Storage::disk('local')->put('backups/host-b1.zip', 'zipdata');
    $backup = TeamBackup::create([
        'team_id' => $team->id,
        'disk' => 'local',
        'path' => 'backups/host-b1.zip',
        'size_bytes' => 7,
        'status' => 'completed',
    ]);

    $actions = collect($table->getRecordActions())->keyBy(fn ($a) => $a->getName());
    expect($actions['download']->record($backup)->isVisible())->toBeTrue()
        ->and($actions['restore']->record($backup)->isVisible())->toBeTrue();

    $downloadResponse = $actions['download']->record($backup)->evaluate($actions['download']->getActionFunction(), ['record' => $backup]);
    expect($downloadResponse)->not->toBeNull();

    $actions['restore']->record($backup)->evaluate($actions['restore']->getActionFunction(), ['record' => $backup]);
    Queue::assertPushed(RestoreTeamBackup::class);

    app()->instance(TeamRestoreService::class, new class() extends TeamRestoreService
    {
        public function firstPopulatedTable(Team $team): ?string
        {
            return 'populated_items';
        }
    });
    $actions['restore']->record($backup)->evaluate($actions['restore']->getActionFunction(), ['record' => $backup]);

    $deleteAction = $actions['delete'];
    $beforeRef = new ReflectionProperty($deleteAction, 'before');
    $beforeClosure = $beforeRef->getValue($deleteAction);
    $deleteAction->record($backup)->evaluate($beforeClosure, ['record' => $backup]);
    Storage::disk('local')->assertMissing('backups/host-b1.zip');

    $generate = hostOrgInvokeProtected($page, 'getHeaderActions')[0];
    $generateSchema = $generate->getSchema(FilamentSchema::make($page));
    $teamSelect = collect($generateSchema->getComponents())->first(fn ($c) => $c->getName() === 'team_id');
    expect($teamSelect->getOptions())->toHaveKey($team->id);

    $generate->evaluate($generate->getActionFunction(), ['data' => ['team_id' => $team->id]]);
    Queue::assertPushed(GenerateTeamBackup::class);
});

it('covers TeamResource and ListTeams, CreateTeam, EditTeam pages', function () {
    Queue::fake();

    $superAdmin = hostOrgUser();
    setPermissionsTeamId(null);
    $saRole = SpatieRole::firstOrCreate(['name' => Role::SuperAdmin->value, 'guard_name' => 'web', 'team_id' => null]);
    $superAdmin->assignRole($saRole);

    $team = hostOrgTeam($superAdmin, ['name' => 'Host Lifecycle Team', 'personal_team' => false]);
    $personal = hostOrgTeam($superAdmin, ['name' => 'Host Personal', 'personal_team' => true]);

    $editPage = new EditTeam();
    expect(TeamResource::isScopedToTenant())->toBeFalse()
        ->and(TeamResource::canAccess())->toBeFalse()
        ->and(TeamResource::getRouteMiddleware(Filament::getPanel('admin')))->toBe([SetFilamentDefaultTenant::class])
        ->and(TeamResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(new CreateTeam())->toBeInstanceOf(CreateTeam::class)
        ->and($editPage)->toBeInstanceOf(EditTeam::class)
        ->and(hostOrgInvokeProtected($editPage, 'getHeaderActions'))->toHaveCount(1);

    Auth::login($superAdmin);
    expect(TeamResource::canAccess())->toBeTrue();

    $plainUser = new class() extends FoundationAuthUser implements OrganizationActor
    {
        public function belongsToTeam($team): bool
        {
            return false;
        }

        public function ownsTeam($team): bool
        {
            return false;
        }

        public function __call($method, $parameters)
        {
            if ($method === 'hasRole') {
                return false;
            }

            return parent::__call($method, $parameters);
        }
    };
    Gate::before(fn ($u) => $u === $plainUser ? true : null);
    Auth::setUser($plainUser);
    expect(TeamResource::canAccess())->toBeTrue();
    Auth::login($superAdmin);

    $page = new ListTeams();
    expect(TeamResource::form(FilamentSchema::make($page))->getComponents())->toHaveCount(3);

    $table = TeamResource::table(Table::make($page));
    (new ReflectionProperty($page, 'table'))->setValue($page, $table);

    // Exercise modifyQueryUsing closure on TeamResource table
    $table->query(Team::query());
    $modifiedQuery = $table->getQuery();
    expect($modifiedQuery->removedScopes())->toContain('archived');

    $statusCol = $table->getColumns()['status'];
    expect($statusCol->record($team)->getState())->toBe('Active')
        ->and($statusCol->getColor('Active'))->toBe('success')
        ->and($statusCol->getColor('Archived'))->toBe('danger');

    $actions = collect($table->getRecordActions())->keyBy(fn ($a) => $a->getName());

    expect($actions['archive']->record($team)->isVisible())->toBeTrue()
        ->and($actions['archive']->record($personal)->isVisible())->toBeFalse()
        ->and($actions['restore']->record($team)->isVisible())->toBeFalse();

    $actions['archive']->record($team)->evaluate($actions['archive']->getActionFunction(), ['record' => $team]);
    $archivedTeam = $team->fresh();
    $statusCol->clearCachedState();
    expect($archivedTeam->isArchived())->toBeTrue()
        ->and($statusCol->record($archivedTeam)->getState())->toBe('Archived')
        ->and($actions['restore']->record($archivedTeam)->isVisible())->toBeTrue();

    $actions['restore']->record($team->fresh())->evaluate($actions['restore']->getActionFunction(), ['record' => $team->fresh()]);
    expect($team->fresh()->isArchived())->toBeFalse();

    $cloneSchema = $actions['clone']->record($team)->getSchema(FilamentSchema::make($page)->record($team));
    $nameField = collect($cloneSchema->getComponents())->first(fn ($c) => $c->getName() === 'name');
    $ownerField = collect($cloneSchema->getComponents())->first(fn ($c) => $c->getName() === 'owner_id');
    expect($nameField->getDefaultState())->toBe("Copy of {$team->name}")
        ->and($ownerField->getDefaultState())->toBe((int) $team->user_id)
        ->and($ownerField->getOptions())->toHaveKey($superAdmin->id);

    $actions['clone']->record($team)->evaluate($actions['clone']->getActionFunction(), [
        'record' => $team,
        'data' => ['name' => 'Host Cloned Via Resource', 'owner_id' => $superAdmin->id],
    ]);
    expect(Team::where('name', 'Host Cloned Via Resource')->exists())->toBeTrue();

    $actions['clone']->record($team)->evaluate($actions['clone']->getActionFunction(), [
        'record' => $team,
        'data' => ['name' => 'Host Missing Owner', 'owner_id' => 999999],
    ]);

    $headerActions = hostOrgInvokeProtected($page, 'getHeaderActions');
    $import = collect($headerActions)->first(fn ($a) => $a->getName() === 'import');
    $import->evaluate($import->getActionFunction(), [
        'data' => ['file' => 'imports/host-sample.zip', 'name' => 'Host From UI'],
    ]);
    Queue::assertPushed(ImportTeamBackup::class);
});

it('covers TeamSubscriptionResource and its List, Create, and Edit pages', function () {
    $listPage = new ListTeamSubscriptions();
    $createPage = new CreateTeamSubscription();
    $editPage = new EditTeamSubscription();

    $form = TeamSubscriptionResource::form(FilamentSchema::make($listPage));
    $table = TeamSubscriptionResource::table(Table::make($listPage));

    expect($form->getComponents())->toHaveCount(7)
        ->and($table->getColumns())->toHaveKeys(['team.name', 'stripe_status', 'quantity', 'trial_ends_at', 'ends_at', 'created_at'])
        ->and($table->getFilters())->toHaveKey('stripe_status')
        ->and(TeamSubscriptionResource::getRelations())->toBe([])
        ->and(TeamSubscriptionResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(hostOrgInvokeProtected($listPage, 'getHeaderActions'))->toHaveCount(1)
        ->and(hostOrgInvokeProtected($editPage, 'getHeaderActions'))->toHaveCount(1)
        ->and($createPage)->toBeInstanceOf(CreateTeamSubscription::class);
});
