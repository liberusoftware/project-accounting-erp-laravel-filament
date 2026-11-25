<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Liberu\Accounting\Erp\Actions\Fortify\CreateNewUser;
use Liberu\Accounting\Erp\Actions\Fortify\CreateNewUserWithTeams;
use Liberu\Accounting\Erp\Actions\Fortify\ResetUserPassword;
use Liberu\Accounting\Erp\Actions\Fortify\UpdateUserPassword;
use Liberu\Accounting\Erp\Actions\Fortify\UpdateUserProfileInformation;
use Liberu\Accounting\Erp\Actions\Jetstream\AddTeamMember;
use Liberu\Accounting\Erp\Actions\Jetstream\CreateTeam;
use Liberu\Accounting\Erp\Actions\Jetstream\DeleteTeam;
use Liberu\Accounting\Erp\Actions\Jetstream\DeleteUser;
use Liberu\Accounting\Erp\Actions\Jetstream\DeleteUserWithTeams;
use Liberu\Accounting\Erp\Actions\Jetstream\InviteTeamMember;
use Liberu\Accounting\Erp\Actions\Jetstream\RemoveTeamMember;
use Liberu\Accounting\Erp\Actions\Jetstream\UpdateTeamName;
use Liberu\Accounting\Erp\Actions\SaveBillOfMaterials;
use Liberu\Accounting\Erp\Http\Middleware\Authenticate;
use Liberu\Accounting\Erp\Http\Middleware\EncryptCookies;
use Liberu\Accounting\Erp\Http\Middleware\EnsureAccountingSetup;
use Liberu\Accounting\Erp\Http\Middleware\EnsurePremiumAccess;
use Liberu\Accounting\Erp\Http\Middleware\PreventRequestsDuringMaintenance;
use Liberu\Accounting\Erp\Http\Middleware\RedirectIfAuthenticated;
use Liberu\Accounting\Erp\Http\Middleware\TeamsPermission;
use Liberu\Accounting\Erp\Http\Middleware\TrimStrings;
use Liberu\Accounting\Erp\Http\Middleware\TrustHosts;
use Liberu\Accounting\Erp\Http\Middleware\TrustProxies;
use Liberu\Accounting\Erp\Http\Middleware\ValidateSignature;
use Liberu\Accounting\Erp\Http\Middleware\VerifyCsrfToken;
use Liberu\Accounting\Erp\Jobs\SyncPlaidTransactionsJob;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BillOfMaterialsComponent;
use Liberu\Accounting\Erp\Models\ConnectedAccount;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Expense;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\Payment;
use Liberu\Accounting\Erp\Models\Plan;
use Liberu\Accounting\Erp\Models\ReminderSetting;
use Liberu\Accounting\Erp\Models\RevenueSchedule;
use Liberu\Accounting\Erp\Models\Subscription;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Notifications\PaymentReminderNotification;
use Liberu\Accounting\Erp\Policies\BillOfMaterialsPolicy;
use Liberu\Accounting\Erp\Services\DocumentService;
use Liberu\Accounting\Erp\Services\InvoicePostingService;
use Liberu\Accounting\Erp\Services\PaymentPostingService;
use Liberu\Accounting\Erp\Services\PostingReversalService;
use Liberu\Accounting\Erp\Services\RevenueRecognitionService;
use Liberu\Accounting\Erp\Services\SubscriptionBillingService;
use Liberu\Accounting\Erp\Services\TeamManagementService;
use Liberu\Accounting\Erp\Services\TenantProvisioningService;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function makeErpUserWithTeam(?string $email = null): array
{
    $user = User::factory()->create($email ? ['email' => $email] : []);
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => $user->name."'s Team",
        'personal_team' => true,
    ]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $team->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);

    return [$user->fresh(), $team->fresh()];
}

// =========================================================================
// Actions/Fortify
// =========================================================================

test('CreateNewUser creates user, assigns team and role, and handles all error paths', function (): void {
    [$owner, $team] = makeErpUserWithTeam('owner-create@example.com');
    setPermissionsTeamId($team->id);
    Role::firstOrCreate(['name' => 'tenant', 'guard_name' => 'web', 'team_id' => $team->id]);

    $action = new CreateNewUser();

    $created = $action->create([
        'name' => 'Alice Tenant',
        'email' => 'alice-tenant@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role' => 'tenant',
    ]);

    expect($created->email)->toBe('alice-tenant@example.com')
        ->and($created->current_team_id)->toBe($team->id)
        ->and($created->hasRole('tenant'))->toBeTrue();

    // ValidationException path
    expect(fn () => $action->create([
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
        'role' => 'invalid-role',
    ]))->toThrow(ValidationException::class);

    // RoleDoesNotExist path ('buyer' passes validation but has no Spatie Role row)
    expect(fn () => $action->create([
        'name' => 'Bob Buyer',
        'email' => 'bob-buyer@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role' => 'buyer',
    ]))->toThrow(Exception::class, 'Invalid role specified. Please choose a valid role.');

    // QueryException path via subclass
    $queryExceptionAction = new class() extends CreateNewUser
    {
        protected function assignOrCreateTeam(User $user): Team
        {
            throw new QueryException('sqlite', 'insert into users', [], new PDOException('Duplicate entry for key', 1062));
        }
    };

    expect(fn () => $queryExceptionAction->create([
        'name' => 'Charlie Query',
        'email' => 'charlie-query@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role' => 'tenant',
    ]))->toThrow(Exception::class, 'A user with this email already exists.');

    // Generic Exception path via subclass
    $genericExceptionAction = new class() extends CreateNewUser
    {
        protected function assignOrCreateTeam(User $user): Team
        {
            throw new RuntimeException('Unexpected boom');
        }
    };

    expect(fn () => $genericExceptionAction->create([
        'name' => 'Dana Boom',
        'email' => 'dana-boom@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role' => 'tenant',
    ]))->toThrow(Exception::class, 'An unexpected error occurred.');

    // Cover all branches of private getDatabaseErrorMessage()
    $method = new ReflectionMethod(CreateNewUser::class, 'getDatabaseErrorMessage');

    $eDup = new QueryException('mysql', 'select 1', [], new PDOException('Duplicate entry', 1062));
    $e1045 = new QueryException('mysql', 'select 1', [], new PDOException('Access denied', 1045));
    $e2002 = new QueryException('mysql', 'select 1', [], new PDOException('Connection refused', 2002));
    $eOther = new QueryException('mysql', 'select 1', [], new PDOException('Deadlock', 1213));

    expect($method->invoke($action, $eDup))->toContain('already exists')
        ->and($method->invoke($action, $e1045))->toContain('Database access denied')
        ->and($method->invoke($action, $e2002))->toContain('Unable to connect to the database')
        ->and($method->invoke($action, $eOther))->toContain('Error code: 1213');
});

test('CreateNewUserWithTeams validates, creates user, and delegates team assignment', function (): void {
    $teamService = Mockery::mock(TeamManagementService::class);
    $teamService->shouldReceive('assignUserToDefaultTeam')->once();
    app()->instance(TeamManagementService::class, $teamService);

    $action = new CreateNewUserWithTeams();
    $user = $action->create([
        'name' => 'Team User',
        'email' => 'team-user@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'terms' => true,
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('team-user@example.com');
});

test('ResetUserPassword resets password and handles validation and generic errors', function (): void {
    $user = User::factory()->create();
    $action = new ResetUserPassword();

    $action->reset($user, [
        'password' => 'NewSecret123!',
        'password_confirmation' => 'NewSecret123!',
    ]);

    expect(Hash::check('NewSecret123!', $user->fresh()->password))->toBeTrue();

    expect(fn () => $action->reset($user, [
        'password' => 'NewSecret123!',
        'password_confirmation' => 'WrongConfirm!',
    ]))->toThrow(ValidationException::class);

    $failingUser = Mockery::mock(User::class)->makePartial();
    $failingUser->id = $user->id;
    $failingUser->shouldReceive('forceFill')->andThrow(new RuntimeException('disk full'));

    expect(fn () => $action->reset($failingUser, [
        'password' => 'NewSecret123!',
        'password_confirmation' => 'NewSecret123!',
    ]))->toThrow(Exception::class, 'Failed to reset password.');
});

test('UpdateUserPassword updates password and handles validation and generic errors', function (): void {
    $user = User::factory()->create(['password' => Hash::make('OldSecret123!')]);
    $this->actingAs($user, 'web');

    $action = new UpdateUserPassword();
    $action->update($user, [
        'current_password' => 'OldSecret123!',
        'password' => 'UpdatedSecret123!',
        'password_confirmation' => 'UpdatedSecret123!',
    ]);

    expect(Hash::check('UpdatedSecret123!', $user->fresh()->password))->toBeTrue();

    expect(fn () => $action->update($user, [
        'current_password' => 'WrongPassword!',
        'password' => 'AnotherSecret123!',
        'password_confirmation' => 'AnotherSecret123!',
    ]))->toThrow(ValidationException::class);

    $failingUser = Mockery::mock(User::class)->makePartial();
    $failingUser->id = $user->id;
    $failingUser->shouldReceive('forceFill')->andThrow(new RuntimeException('save failed'));

    expect(fn () => $action->update($failingUser, [
        'current_password' => 'UpdatedSecret123!',
        'password' => 'FinalSecret123!',
        'password_confirmation' => 'FinalSecret123!',
    ]))->toThrow(Exception::class, 'Failed to update password.');
});

test('UpdateUserProfileInformation updates regular and MustVerifyEmail users', function (): void {
    Notification::fake();

    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);
    $action = new UpdateUserProfileInformation();

    $action->update($user, [
        'name' => 'New Name',
        'email' => 'new@example.com',
    ]);

    expect($user->fresh()->name)->toBe('New Name')
        ->and($user->fresh()->email)->toBe('new@example.com');

    // MustVerifyEmail subclass triggers updateVerifiedUser()
    $verifiable = new class() extends User implements MustVerifyEmail
    {
        protected $table = 'users';

        public bool $verificationSent = false;

        public function sendEmailVerificationNotification(): void
        {
            $this->verificationSent = true;
        }
    };
    $verifiable->forceFill([
        'name' => 'Verify Me',
        'email' => 'verify-old@example.com',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ])->save();

    $action->update($verifiable, [
        'name' => 'Verified Updated',
        'email' => 'verify-new@example.com',
    ]);

    expect($verifiable->fresh()->email)->toBe('verify-new@example.com')
        ->and($verifiable->fresh()->email_verified_at)->toBeNull()
        ->and($verifiable->verificationSent)->toBeTrue();
});

// =========================================================================
// Actions/Jetstream
// =========================================================================

test('Jetstream team actions cover create, update, add, invite, remove, delete team and users', function (): void {
    Event::fake([
        AddingTeam::class,
        AddingTeamMember::class,
        TeamMemberAdded::class,
        InvitingTeamMember::class,
        TeamMemberRemoved::class,
    ]);
    Mail::fake();

    Gate::define('create', fn () => true);
    Gate::define('update', fn () => true);
    Gate::define('addTeamMember', fn () => true);
    Gate::define('removeTeamMember', fn (User $actor, Team $team) => $actor->ownsTeam($team));

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    // CreateTeam
    $team = (new CreateTeam())->create($owner, ['name' => 'Alpha Corp']);
    expect($team->name)->toBe('Alpha Corp')
        ->and($owner->fresh()->current_team_id)->toBe($team->id);
    Event::assertDispatched(AddingTeam::class);

    // UpdateTeamName
    (new UpdateTeamName())->update($owner, $team, ['name' => 'Alpha Corp Renamed']);
    expect($team->fresh()->name)->toBe('Alpha Corp Renamed');

    // AddTeamMember
    $addAction = new AddTeamMember();
    $addAction->add($owner, $team, $member->email, 'editor');
    expect($team->fresh()->hasUserWithEmail($member->email))->toBeTrue();
    Event::assertDispatched(AddingTeamMember::class);
    Event::assertDispatched(TeamMemberAdded::class);

    // Duplicate AddTeamMember fails validation
    expect(fn () => $addAction->add($owner, $team, $member->email, 'editor'))
        ->toThrow(ValidationException::class);

    // InviteTeamMember
    $inviteAction = new InviteTeamMember();
    $inviteAction->invite($owner, $team, 'invited@example.com', 'editor');
    Event::assertDispatched(InvitingTeamMember::class);
    Mail::assertSent(TeamInvitationMail::class);

    // Duplicate invitation fails validation
    expect(fn () => $inviteAction->invite($owner, $team, 'invited@example.com', 'editor'))
        ->toThrow(ValidationException::class);

    // Inviting existing member fails validation
    expect(fn () => $inviteAction->invite($owner, $team, $member->email, 'editor'))
        ->toThrow(ValidationException::class);

    // RemoveTeamMember: unauthorized actor throws AuthorizationException
    $removeAction = new RemoveTeamMember();
    expect(fn () => $removeAction->remove($outsider, $team, $member))
        ->toThrow(AuthorizationException::class);

    // RemoveTeamMember: cannot remove owner
    expect(fn () => $removeAction->remove($owner, $team, $owner))
        ->toThrow(ValidationException::class);

    // RemoveTeamMember: owner removes member
    $removeAction->remove($owner, $team, $member);
    expect($team->fresh()->hasUserWithEmail($member->email))->toBeFalse();
    Event::assertDispatched(TeamMemberRemoved::class);

    // DeleteTeam
    $secondTeam = (new CreateTeam())->create($owner, ['name' => 'Temp Team']);
    (new DeleteTeam())->delete($secondTeam);
    expect(Team::find($secondTeam->id))->toBeNull();

    // DeleteUser
    $disposable = User::factory()->create();
    $disposable->createToken('test-token');
    ConnectedAccount::factory()->create(['user_id' => $disposable->id]);
    (new DeleteUser())->delete($disposable->fresh());
    expect(User::find($disposable->id))->toBeNull();

    // DeleteUserWithTeams
    [$teamOwner, $ownedTeam] = makeErpUserWithTeam('delete-with-teams@example.com');
    $teamOwner->createToken('api-token');
    $deletesTeams = new DeleteTeam();
    (new DeleteUserWithTeams($deletesTeams))->delete($teamOwner->fresh());
    expect(User::find($teamOwner->id))->toBeNull()
        ->and(Team::find($ownedTeam->id))->toBeNull();
});

// =========================================================================
// Actions/SaveBillOfMaterials
// =========================================================================

test('SaveBillOfMaterials enforces authorization, team scoping, cycle detection, and saves valid BOM', function (): void {
    [$user, $team] = makeErpUserWithTeam('bom-owner@example.com');
    [, $otherTeam] = makeErpUserWithTeam('bom-other@example.com');

    $account = Account::factory()->create(['user_id' => $user->id, 'team_id' => $team->id]);
    $itemA = InventoryItem::factory()->create(['team_id' => $team->id, 'account_id' => $account->id]);
    $itemB = InventoryItem::factory()->create(['team_id' => $team->id, 'account_id' => $account->id]);
    $itemC = InventoryItem::factory()->create(['team_id' => $team->id, 'account_id' => $account->id]);
    $otherItem = InventoryItem::factory()->create(['team_id' => $otherTeam->id, 'account_id' => $account->id]);

    $action = new SaveBillOfMaterials(new BillOfMaterialsPolicy());

    // Module disabled -> AuthorizationException
    config(['modules.disabled' => ['accounting-inventory-accounting']]);
    expect(fn () => $action->handle($user, $itemA, [
        ['component_item_id' => $itemB->id, 'quantity' => 2],
    ]))->toThrow(AuthorizationException::class);

    // Enable module for remaining checks
    config(['modules.disabled' => [], 'modules.enabled' => ['accounting-inventory-accounting']]);

    // Item missing from DB inside transaction -> AuthorizationException
    $ghostItem = new InventoryItem();
    $ghostItem->id = 999999;
    $ghostItem->team_id = $team->id;
    expect(fn () => $action->handle($user, $ghostItem, [
        ['component_item_id' => $itemB->id, 'quantity' => 1],
    ]))->toThrow(AuthorizationException::class);

    // Component from another team -> ValidationException
    expect(fn () => $action->handle($user, $itemA, [
        ['component_item_id' => $otherItem->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);

    // Direct self-cycle -> ValidationException
    expect(fn () => $action->handle($user, $itemA, [
        ['component_item_id' => $itemA->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);

    // Valid save: B -> C, then A -> B and A -> C (diamond covers $visited continue branch)
    $action->handle($user, $itemB, [
        ['component_item_id' => $itemC->id, 'quantity' => 3],
    ]);
    $action->handle($user, $itemA, [
        ['component_item_id' => $itemB->id, 'quantity' => 2],
        ['component_item_id' => $itemC->id, 'quantity' => 1.5],
    ]);

    expect(BillOfMaterialsComponent::where('item_id', $itemA->id)->count())->toBe(2);

    // Transitive cycle: C -> A now creates cycle A -> B -> C -> A
    expect(fn () => $action->handle($user, $itemC, [
        ['component_item_id' => $itemA->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

// =========================================================================
// Console/Commands (all 14 commands)
// =========================================================================

test('BackfillDocuments registers legacy document_path and is idempotent', function (): void {
    $invoice = Invoice::factory()->create(['document_path' => 'legacy/inv-001.pdf']);

    $this->artisan('documents:backfill')
        ->expectsOutput('Backfilled 1 document(s).')
        ->assertSuccessful();

    expect($invoice->documents()->count())->toBe(1);

    // Second run skips already backfilled path
    $this->artisan('documents:backfill')
        ->expectsOutput('Backfilled 0 document(s).')
        ->assertSuccessful();
});

test('BackfillTenancy supports dry-run and real backfill', function (): void {
    $user = User::factory()->create(['current_team_id' => null]);
    $account = Account::factory()->create(['user_id' => $user->id, 'team_id' => null]);

    $this->artisan('teams:backfill-tenancy', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] Provisioned personal teams')
        ->expectsOutputToContain('[dry-run] Backfilled team_id')
        ->assertSuccessful();

    expect($account->fresh()->team_id)->toBeNull();

    $this->artisan('teams:backfill-tenancy')
        ->expectsOutputToContain('Provisioned personal teams')
        ->expectsOutputToContain('Backfilled team_id')
        ->assertSuccessful();

    expect($user->fresh()->current_team_id)->not->toBeNull()
        ->and($account->fresh()->team_id)->toBe($user->fresh()->current_team_id);
});

test('CalculateLateFees calculates late fees on overdue invoices', function (): void {
    $invoice = Invoice::factory()->create([
        'payment_status' => 'pending',
        'due_date' => now()->subDays(15)->toDateString(),
        'total_amount' => 1000.00,
    ]);
    $invoice->forceFill([
        'grace_period_days' => 5,
        'late_fee_percentage' => 10,
    ])->save();

    $this->artisan('invoices:calculate-late-fees')
        ->expectsOutput('Late fees calculated successfully.')
        ->assertSuccessful();

    expect((float) $invoice->fresh()->late_fee_amount)->toBe(100.0);
});

test('PostInvoice and UnpostInvoice handle missing, skipped, failing, and successful cases', function (): void {
    $this->artisan('invoices:post', ['invoice' => 999999])->assertFailed();
    $this->artisan('invoices:unpost', ['invoice' => 999999])->assertFailed();

    $entry = JournalEntry::factory()->create();
    $invoice = Invoice::factory()->create(['journal_entry_id' => null]);

    // Unpost when not posted -> skipped
    $this->artisan('invoices:unpost', ['invoice' => $invoice->id])
        ->expectsOutput("Invoice {$invoice->id} is not posted; skipped.")
        ->assertSuccessful();

    // Post fails with RuntimeException
    $postService = Mockery::mock(InvoicePostingService::class);
    $postService->shouldReceive('post')->once()->andThrow(new RuntimeException('No AR account'));
    app()->instance(InvoicePostingService::class, $postService);

    $this->artisan('invoices:post', ['invoice' => $invoice->id])
        ->expectsOutput('No AR account')
        ->assertFailed();

    // Post succeeds
    $postServiceOk = Mockery::mock(InvoicePostingService::class);
    $postServiceOk->shouldReceive('post')->once()->andReturn($entry);
    app()->instance(InvoicePostingService::class, $postServiceOk);

    $this->artisan('invoices:post', ['invoice' => $invoice->id])
        ->expectsOutput("Posted invoice {$invoice->id} to ledger (entry {$entry->id}).")
        ->assertSuccessful();

    // Already posted -> skipped
    $invoice->forceFill(['journal_entry_id' => $entry->id])->save();
    $this->artisan('invoices:post', ['invoice' => $invoice->id])
        ->expectsOutput("Invoice {$invoice->id} already posted (entry {$entry->id}); skipped.")
        ->assertSuccessful();

    // Unpost fails with Throwable
    $reversalFail = Mockery::mock(PostingReversalService::class);
    $reversalFail->shouldReceive('reverseInvoice')->once()->andThrow(new RuntimeException('Period locked'));
    app()->instance(PostingReversalService::class, $reversalFail);

    $this->artisan('invoices:unpost', ['invoice' => $invoice->id])
        ->expectsOutput('Period locked')
        ->assertFailed();

    // Unpost succeeds
    $reversalOk = Mockery::mock(PostingReversalService::class);
    $reversalOk->shouldReceive('reverseInvoice')->once();
    app()->instance(PostingReversalService::class, $reversalOk);

    $this->artisan('invoices:unpost', ['invoice' => $invoice->id])
        ->expectsOutput("Unposted invoice {$invoice->id}.")
        ->assertSuccessful();
});

test('PostPayment and UnpostPayment handle missing, skipped, failing, and successful cases', function (): void {
    $this->artisan('payments:post', ['payment' => 999999])->assertFailed();
    $this->artisan('payments:unpost', ['payment' => 999999])->assertFailed();

    $entry = JournalEntry::factory()->create();
    $invoice = Invoice::factory()->create();
    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'payment_date' => now()->toDateString(),
        'payment_amount' => 250.00,
        'journal_entry_id' => null,
    ]);
    $paymentId = (int) $payment->getKey();

    // Unpost when not posted -> skipped
    $this->artisan('payments:unpost', ['payment' => $paymentId])
        ->expectsOutput("Payment {$paymentId} is not posted; skipped.")
        ->assertSuccessful();

    // Post fails
    $postService = Mockery::mock(PaymentPostingService::class);
    $postService->shouldReceive('post')->once()->andThrow(new RuntimeException('Missing Cash account'));
    app()->instance(PaymentPostingService::class, $postService);

    $this->artisan('payments:post', ['payment' => $paymentId])
        ->expectsOutput('Missing Cash account')
        ->assertFailed();

    // Post succeeds
    $postServiceOk = Mockery::mock(PaymentPostingService::class);
    $postServiceOk->shouldReceive('post')->once()->andReturn($entry);
    app()->instance(PaymentPostingService::class, $postServiceOk);

    $this->artisan('payments:post', ['payment' => $paymentId])
        ->expectsOutput("Posted payment {$paymentId} to ledger (entry {$entry->id}).")
        ->assertSuccessful();

    // Already posted -> skipped
    $payment->forceFill(['journal_entry_id' => $entry->id])->save();
    $this->artisan('payments:post', ['payment' => $paymentId])
        ->expectsOutput("Payment {$paymentId} already posted (entry {$entry->id}); skipped.")
        ->assertSuccessful();

    // Unpost fails
    $reversalFail = Mockery::mock(PostingReversalService::class);
    $reversalFail->shouldReceive('reversePayment')->once()->andThrow(new RuntimeException('Locked period'));
    app()->instance(PostingReversalService::class, $reversalFail);

    $this->artisan('payments:unpost', ['payment' => $paymentId])
        ->expectsOutput('Locked period')
        ->assertFailed();

    // Unpost succeeds
    $reversalOk = Mockery::mock(PostingReversalService::class);
    $reversalOk->shouldReceive('reversePayment')->once();
    app()->instance(PostingReversalService::class, $reversalOk);

    $this->artisan('payments:unpost', ['payment' => $paymentId])
        ->expectsOutput("Unposted payment {$paymentId}.")
        ->assertSuccessful();
});

test('ProcessRecurringTransactions generates due occurrences and isolates template errors', function (): void {
    $user = User::factory()->create();
    Expense::create([
        'user_id' => $user->id,
        'amount' => 50.00,
        'description' => 'Monthly Hosting',
        'date' => today(),
        'is_recurring' => true,
        'recurrence_frequency' => 'monthly',
        'recurrence_start' => today(),
    ]);

    $this->artisan('recurring:process')
        ->expectsOutput('Generated 1 recurring document(s).')
        ->assertSuccessful();

    // Trigger catch(\Throwable) branch on a failing recurring invoice template
    $throwOnce = true;
    Invoice::creating(function () use (&$throwOnce): void {
        if ($throwOnce) {
            $throwOnce = false;
            throw new RuntimeException('Simulated recurring clone failure');
        }
    });

    Invoice::withoutEvents(function (): void {
        Invoice::factory()->create([
            'is_recurring' => true,
            'recurrence_frequency' => 'daily',
            'recurrence_start' => today(),
            'last_generated' => null,
        ]);
    });

    try {
        $this->artisan('recurring:process')
            ->expectsOutput('Generated 0 recurring document(s).')
            ->assertSuccessful();
    } finally {
        $throwOnce = false;
    }
});

test('ProcessSubscriptions, ProvisionTenantChart, PruneDocuments, and RecognizeRevenue commands execute cleanly', function (): void {
    [$user, $team] = makeErpUserWithTeam('cmd-owner@example.com');
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $plan = Plan::create([
        'name' => 'Pro',
        'amount' => 99.00,
        'currency' => 'USD',
        'interval' => 'monthly',
        'team_id' => $team->id,
    ]);
    Subscription::create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => today()->subMonth(),
        'next_billing_date' => today(),
        'team_id' => $team->id,
    ]);

    $subService = Mockery::mock(SubscriptionBillingService::class);
    $subService->shouldReceive('generateDueInvoices')->once()->andReturn(2);
    app()->instance(SubscriptionBillingService::class, $subService);

    $this->artisan('subscriptions:process')
        ->expectsOutput('Generated 2 subscription invoice(s).')
        ->assertSuccessful();

    // ProvisionTenantChart: missing, provisioned, skipped
    $this->artisan('tenants:provision-chart', ['team' => 999999])->assertFailed();

    $provService = Mockery::mock(TenantProvisioningService::class);
    $provService->shouldReceive('provisionChartOfAccounts')->twice()->andReturn(12, 0);
    app()->instance(TenantProvisioningService::class, $provService);

    $this->artisan('tenants:provision-chart', ['team' => $team->id])
        ->expectsOutput("Provisioned 12 accounts for team {$team->id}.")
        ->assertSuccessful();

    $this->artisan('tenants:provision-chart', ['team' => $team->id])
        ->expectsOutput("Team {$team->id} already has accounts; skipped.")
        ->assertSuccessful();

    // PruneDocuments
    $docService = Mockery::mock(DocumentService::class);
    $docService->shouldReceive('prune')->once()->andReturn(3);
    app()->instance(DocumentService::class, $docService);

    $this->artisan('documents:prune')
        ->expectsOutput('Pruned 3 document(s).')
        ->assertSuccessful();

    // RecognizeRevenue: one succeeds, one throws
    $invoice = Invoice::factory()->create(['team_id' => $team->id]);
    $deferred = Account::factory()->create(['user_id' => $user->id, 'team_id' => $team->id]);
    $revenue = Account::factory()->create(['user_id' => $user->id, 'team_id' => $team->id]);

    $sched1 = RevenueSchedule::create([
        'invoice_id' => $invoice->id,
        'total_amount' => 1200,
        'start_date' => today(),
        'periods' => 12,
        'deferred_account_id' => $deferred->id,
        'revenue_account_id' => $revenue->id,
        'status' => 'active',
        'team_id' => $team->id,
    ]);
    $sched2 = RevenueSchedule::create([
        'invoice_id' => $invoice->id,
        'total_amount' => 600,
        'start_date' => today(),
        'periods' => 6,
        'deferred_account_id' => $deferred->id,
        'revenue_account_id' => $revenue->id,
        'status' => 'active',
        'team_id' => $team->id,
    ]);

    $revService = Mockery::mock(RevenueRecognitionService::class);
    $revService->shouldReceive('recognizeDue')
        ->andReturnUsing(function (RevenueSchedule $s) use ($sched1): int {
            if ($s->getKey() === $sched1->getKey()) {
                return 1;
            }
            throw new RuntimeException('Period closed');
        });
    app()->instance(RevenueRecognitionService::class, $revService);

    $this->artisan('revenue:recognize')
        ->expectsOutput("Schedule #{$sched2->getKey()} skipped: Period closed")
        ->expectsOutput('Recognised 1 revenue period(s).')
        ->assertSuccessful();
});

test('SendPaymentReminders handles inactive settings and sends reminders for overdue invoices', function (): void {
    Notification::fake();

    // No settings -> not active
    $this->artisan('invoices:send-reminders')
        ->expectsOutput('Reminder system is not active')
        ->assertSuccessful();

    ReminderSetting::create([
        'days_before_reminder' => 5,
        'reminder_frequency_days' => 3,
        'max_reminders' => 3,
        'is_active' => true,
    ]);

    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'payment_status' => 'pending',
        'invoice_date' => now()->subDays(10)->toDateString(),
        'reminders_sent' => 0,
        'last_reminder_sent_at' => null,
    ]);

    // Populate virtual `email` property on hydrated Customer so SendPaymentReminders sends notification
    $injectEmail = true;
    Customer::retrieved(function (Customer $c) use (&$injectEmail): void {
        if ($injectEmail) {
            $c->setAttribute('email', $c->customer_email);
        }
    });

    try {
        $this->artisan('invoices:send-reminders')
            ->expectsOutput("Reminder sent for Invoice #{$invoice->id}")
            ->assertSuccessful();
    } finally {
        $injectEmail = false;
    }

    expect($invoice->fresh()->reminders_sent)->toBe(1)
        ->and($invoice->fresh()->last_reminder_sent_at)->not->toBeNull();
    Notification::assertSentTo($customer, PaymentReminderNotification::class);
});

test('SyncPlaidTransactions command handles validation, single connection, and --all modes', function (): void {
    Queue::fake();

    // Missing flags -> FAILURE
    $this->artisan('plaid:sync-transactions')
        ->expectsOutput('Please specify either --connection=ID or --all')
        ->assertFailed();

    // Missing connection -> FAILURE
    $this->artisan('plaid:sync-transactions', ['--connection' => 999999])
        ->expectsOutput('Connection 999999 not found')
        ->assertFailed();

    // --all with no active connections
    $this->artisan('plaid:sync-transactions', ['--all' => true])
        ->expectsOutput('No active Plaid connections found')
        ->assertSuccessful();

    $inactive = BankConnection::factory()->disconnected()->create();
    $this->artisan('plaid:sync-transactions', ['--connection' => $inactive->id])
        ->expectsOutput("Connection {$inactive->id} is not active (status: disconnected)")
        ->assertFailed();

    $active = BankConnection::factory()->active()->create(['plaid_access_token' => 'access-sandbox-123']);

    $this->artisan('plaid:sync-transactions', ['--connection' => $active->id])
        ->expectsOutput('Sync job dispatched successfully')
        ->assertSuccessful();

    Queue::assertPushed(SyncPlaidTransactionsJob::class, fn (SyncPlaidTransactionsJob $job) => $job->connectionId === $active->id);

    $this->artisan('plaid:sync-transactions', ['--all' => true])
        ->expectsOutput('All sync jobs dispatched successfully')
        ->assertSuccessful();
});

// =========================================================================
// Http/Middleware (all 12 middleware classes)
// =========================================================================

test('standard Laravel middleware subclasses instantiate and return expected config', function (): void {
    if (! Route::has('login')) {
        Route::get('/_test-login', fn () => 'login')->name('login');
    }

    $auth = new class(app('auth')) extends Authenticate
    {
        public function exposeRedirectTo(Request $request): ?string
        {
            return $this->redirectTo($request);
        }
    };

    $jsonReq = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $webReq = Request::create('/dashboard', 'GET');

    expect($auth->exposeRedirectTo($jsonReq))->toBeNull()
        ->and($auth->exposeRedirectTo($webReq))->toBe(route('login'));

    $trustHosts = new TrustHosts(app());
    expect($trustHosts->hosts())->toBeArray()->toHaveCount(1);

    expect(new EncryptCookies(app('encrypter')))->toBeInstanceOf(EncryptCookies::class)
        ->and(new PreventRequestsDuringMaintenance(app()))->toBeInstanceOf(PreventRequestsDuringMaintenance::class)
        ->and(new TrimStrings())->toBeInstanceOf(TrimStrings::class)
        ->and(new TrustProxies())->toBeInstanceOf(TrustProxies::class)
        ->and(new ValidateSignature())->toBeInstanceOf(ValidateSignature::class)
        ->and(new VerifyCsrfToken(app(), app('encrypter')))->toBeInstanceOf(VerifyCsrfToken::class);
});

test('EnsureAccountingSetup redirects unconfigured owners and passes through otherwise', function (): void {
    [$owner, $team] = makeErpUserWithTeam('setup-owner@example.com');
    $team->forceFill(['accounting_setup_completed_at' => null])->save();

    $middleware = new EnsureAccountingSetup();
    $next = fn (Request $req) => new Response('ok');

    $req = Request::create('/app/invoices', 'GET');
    $req->setUserResolver(fn () => $owner->fresh());
    $req->setRouteResolver(fn () => (new RoutingRoute('GET', '/app/invoices', []))->name('filament.app.pages.dashboard'));

    $res = $middleware->handle($req, $next);
    expect($res->getStatusCode())->toBe(302)
        ->and($res->headers->get('Location'))->toContain('/app/account-setup');

    // Setup route itself passes through
    $setupReq = Request::create('/app/account-setup', 'GET');
    $setupReq->setUserResolver(fn () => $owner->fresh());
    $setupReq->setRouteResolver(fn () => (new RoutingRoute('GET', '/app/account-setup', []))->name('filament.app.pages.account-setup'));
    expect($middleware->handle($setupReq, $next)->getContent())->toBe('ok');

    // Completed team passes through
    $team->forceFill(['accounting_setup_completed_at' => now()])->save();
    expect($middleware->handle($req, $next)->getContent())->toBe('ok');
});

test('EnsurePremiumAccess gates routes when premium is enabled and allows exempt routes', function (): void {
    if (! Route::has('billing.premium')) {
        Route::get('/_test-billing-premium', fn () => 'billing')->name('billing.premium');
    }

    [$user, $team] = makeErpUserWithTeam('premium-user@example.com');
    $team->forceFill(['premium_status' => 'canceled'])->save();

    $middleware = new EnsurePremiumAccess();
    $next = fn (Request $req) => new Response('ok');

    $makeReq = function (string $routeName) use ($user): Request {
        $req = Request::create('/test', 'GET');
        $req->setUserResolver(fn () => $user->fresh());
        $req->setRouteResolver(fn () => (new RoutingRoute('GET', '/test', []))->name($routeName));

        return $req;
    };

    // Disabled -> passes through
    config(['premium.enabled' => false]);
    expect($middleware->handle($makeReq('invoices.index'), $next)->getContent())->toBe('ok');

    // Enabled + non-premium + gated route -> redirects to billing.premium
    config(['premium.enabled' => true]);
    $blocked = $middleware->handle($makeReq('invoices.index'), $next);
    expect($blocked->getStatusCode())->toBe(302)
        ->and($blocked->headers->get('Location'))->toBe(route('billing.premium'));

    // Enabled + non-premium + exempt routes -> pass through
    foreach (['filament.app.pages.account-setup', 'dashboard', 'app.dashboard', 'reports.overview', 'stripe.payment', 'data.export', 'billing.premium.checkout'] as $allowed) {
        expect($middleware->handle($makeReq($allowed), $next)->getContent())->toBe('ok');
    }

    // Enabled + active premium -> passes through
    $team->forceFill(['premium_status' => 'active', 'premium_current_period_ends_at' => now()->addMonth()])->save();
    expect($middleware->handle($makeReq('invoices.index'), $next)->getContent())->toBe('ok');
});

test('RedirectIfAuthenticated redirects by mapped role, custom role, default home, or passes guest', function (): void {
    $middleware = new RedirectIfAuthenticated();
    $next = fn (Request $req) => new Response('guest-ok');

    // Guest passes through
    expect($middleware->handle(Request::create('/login', 'GET'), $next)->getContent())->toBe('guest-ok');

    [$user, $team] = makeErpUserWithTeam('redirect-user@example.com');
    setPermissionsTeamId($team->id);
    $this->actingAs($user);

    // No roles -> fortify.home
    $resHome = $middleware->handle(Request::create('/login', 'GET'), $next);
    expect($resHome->getStatusCode())->toBe(302)
        ->and($resHome->headers->get('Location'))->toContain(config('fortify.home', '/app'));

    // Custom unmapped role -> /{role}
    Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->syncRoles(['auditor']);
    $resCustom = $middleware->handle(Request::create('/login', 'GET'), $next);
    expect($resCustom->headers->get('Location'))->toEndWith('/auditor');

    // Mapped role -> /admin
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->syncRoles(['admin']);
    $resAdmin = $middleware->handle(Request::create('/login', 'GET'), $next);
    expect($resAdmin->headers->get('Location'))->toEndWith('/admin');
});

test('TeamsPermission enforces auth, role, team membership, tenant matching, and route permissions', function (): void {
    if (! Route::has('login')) {
        Route::get('/_test-login', fn () => 'login')->name('login');
    }
    if (! Route::has('home')) {
        Route::get('/_test-home', fn () => 'home')->name('home');
    }
    if (! Route::has('staff.dashboard')) {
        Route::get('/_test-staff/{tenant}', fn () => 'staff')->name('staff.dashboard');
    }

    $middleware = new TeamsPermission();
    $next = fn (Request $req) => new Response('allowed');

    // Guest -> login
    $guestRes = $middleware->handle(Request::create('/staff', 'GET'), $next);
    expect($guestRes->headers->get('Location'))->toBe(route('login'));

    // Authenticated without allowed role -> home
    [$user, $team] = makeErpUserWithTeam('tp-user@example.com');
    setPermissionsTeamId($team->id);
    $this->actingAs($user);
    $noRoleRes = $middleware->handle(Request::create('/staff', 'GET'), $next);
    expect($noRoleRes->headers->get('Location'))->toBe(route('home'));

    // Admin bypasses team and permission checks
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->syncRoles(['admin']);
    expect($middleware->handle(Request::create('/staff', 'GET'), $next)->getContent())->toBe('allowed');

    // Accountant without currentTeam -> home
    Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->syncRoles(['accountant']);
    $user->forceFill(['current_team_id' => null])->save();
    $this->actingAs($user->fresh());
    $noTeamRes = $middleware->handle(Request::create('/staff', 'GET'), $next);
    expect($noTeamRes->headers->get('Location'))->toBe(route('home'));

    // Accountant with currentTeam but mismatched tenant param -> staff.dashboard
    $user->forceFill(['current_team_id' => $team->id])->save();
    $this->actingAs($user->fresh());

    $mismatchRoute = (new RoutingRoute('GET', '/staff/{tenant}/users', []))->name('users.index');
    $mismatchReq = Request::create('/staff/99999/users', 'GET');
    $mismatchRoute->bind($mismatchReq);
    $mismatchReq->setRouteResolver(fn () => $mismatchRoute);

    $mismatchRes = $middleware->handle($mismatchReq, $next);
    expect($mismatchRes->headers->get('Location'))->toBe(route('staff.dashboard', ['tenant' => $team->id]));

    // Accountant on users.index without manage_users permission -> redirect back
    $permRoute = (new RoutingRoute('GET', '/staff/{tenant}/users', []))->name('users.index');
    $permReq = Request::create("/staff/{$team->id}/users", 'GET');
    $permRoute->bind($permReq);
    $permReq->setRouteResolver(fn () => $permRoute);

    $deniedRes = $middleware->handle($permReq, $next);
    expect($deniedRes->getStatusCode())->toBe(302);

    // Grant manage_users -> allowed
    Permission::firstOrCreate(['name' => 'manage_users', 'guard_name' => 'web']);
    $user->givePermissionTo('manage_users');
    $this->actingAs($user->fresh());
    expect($middleware->handle($permReq, $next)->getContent())->toBe('allowed');
});
