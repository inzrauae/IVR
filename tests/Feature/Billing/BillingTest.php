<?php

use App\Authorization\Role;
use App\Billing\BillingService;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\Tenant;

function subscriptionOf(Organization $org): Subscription
{
    return $org->fresh()->currentSubscription;
}

it('invoices a trial that has ended and activates the organization', function () {
    $owner = User::factory()->create();
    $org = $owner->organization;
    $org->forceFill(['status' => Organization::STATUS_TRIAL])->save();
    subscriptionOf($org)->forceFill(['status' => 'trialing', 'trial_ends_at' => now()->subDay(), 'current_period_end' => now()->subDay()])->save();

    $stats = app(BillingService::class)->run();

    $invoice = Invoice::withoutGlobalScopes()->where('organization_id', $org->id)->sole();

    expect($stats['invoiced'])->toBe(1)
        ->and((float) $invoice->total)->toBe(7500.0)
        ->and($invoice->status)->toBe(Invoice::STATUS_OPEN)
        ->and($invoice->number)->toStartWith('INV-')
        ->and(subscriptionOf($org)->status)->toBe('active')
        ->and(subscriptionOf($org)->current_period_end->isFuture())->toBeTrue()
        ->and($org->fresh()->status)->toBe(Organization::STATUS_ACTIVE);
});

it('does not invoice subscriptions whose period has not ended', function () {
    User::factory()->create();

    expect(app(BillingService::class)->run()['invoiced'])->toBe(0);
});

it('adds tax when configured', function () {
    config(['billing.tax_rate' => 18]);
    $org = User::factory()->create()->organization;
    subscriptionOf($org)->forceFill(['current_period_end' => now()->subMinute()])->save();

    app(BillingService::class)->run();

    $invoice = Invoice::withoutGlobalScopes()->where('organization_id', $org->id)->sole();
    expect((float) $invoice->tax)->toBe(1350.0)->and((float) $invoice->total)->toBe(8850.0);
});

it('marks overdue accounts past due, then suspends after the grace period', function () {
    $org = User::factory()->create()->organization;
    subscriptionOf($org)->forceFill(['current_period_end' => now()->subMinute()])->save();
    app(BillingService::class)->run();
    $invoice = Invoice::withoutGlobalScopes()->where('organization_id', $org->id)->sole();

    // Just overdue: past due, not suspended.
    $invoice->forceFill(['due_at' => now()->subDay()])->save();
    app(BillingService::class)->run();
    expect(Subscription::withoutGlobalScopes()->where('organization_id', $org->id)->value('status'))->toBe('past_due')
        ->and($org->fresh()->isSuspended())->toBeFalse();

    // Beyond the 7-day grace period: suspended for billing.
    $invoice->forceFill(['due_at' => now()->subDays(8)])->save();
    app(BillingService::class)->run();
    expect($org->fresh()->isSuspended())->toBeTrue()
        ->and($org->fresh()->suspension_source)->toBe(Organization::SUSPENDED_BY_BILLING);
});

it('reactivates a billing suspension when the invoice is paid in full', function () {
    $admin = User::factory()->platformAdmin()->create();
    $org = User::factory()->create()->organization;
    subscriptionOf($org)->forceFill(['current_period_end' => now()->subMinute()])->save();
    app(BillingService::class)->run();
    $invoice = Invoice::withoutGlobalScopes()->where('organization_id', $org->id)->sole();
    $invoice->forceFill(['due_at' => now()->subDays(10)])->save();
    app(BillingService::class)->run();
    expect($org->fresh()->isSuspended())->toBeTrue();

    // A partial payment leaves the account suspended.
    $this->actingAs($admin)
        ->post(route('platform.invoices.payments.store', $invoice->id), ['amount' => 2500, 'method' => 'bank_transfer'])
        ->assertSessionHas('success');
    expect($org->fresh()->isSuspended())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(Invoice::STATUS_OPEN);

    $this->actingAs($admin)
        ->post(route('platform.invoices.payments.store', $invoice->id), ['amount' => 5000, 'method' => 'bank_transfer', 'reference' => 'BOC-123'])
        ->assertSessionHas('success');

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and($org->fresh()->isSuspended())->toBeFalse()
        ->and(subscriptionOf($org)->status)->toBe('active');
});

it('does not lift an admin suspension when an invoice is paid', function () {
    $admin = User::factory()->platformAdmin()->create();
    $org = User::factory()->create()->organization;
    subscriptionOf($org)->forceFill(['current_period_end' => now()->subMinute()])->save();
    app(BillingService::class)->run();
    $org->suspend('Abuse report', Organization::SUSPENDED_BY_ADMIN);
    $invoice = Invoice::withoutGlobalScopes()->where('organization_id', $org->id)->sole();

    $this->actingAs($admin)->post(route('platform.invoices.payments.store', $invoice->id), ['amount' => 7500, 'method' => 'cash']);

    expect($org->fresh()->isSuspended())->toBeTrue();
});

it('rejects overpayments', function () {
    $admin = User::factory()->platformAdmin()->create();
    $org = User::factory()->create()->organization;
    subscriptionOf($org)->forceFill(['current_period_end' => now()->subMinute()])->save();
    app(BillingService::class)->run();
    $invoice = Invoice::withoutGlobalScopes()->where('organization_id', $org->id)->sole();

    $this->actingAs($admin)
        ->post(route('platform.invoices.payments.store', $invoice->id), ['amount' => 9999, 'method' => 'cash'])
        ->assertSessionHasErrors('amount');
});

it('lets a billing-suspended organization still reach its billing page', function () {
    $owner = User::factory()->create();
    $owner->organization->suspend('Invoice overdue', Organization::SUSPENDED_BY_BILLING);

    $this->actingAs($owner)->get(route('billing.index'))->assertOk();
    $this->actingAs($owner)->get(route('dashboard'))->assertForbidden()
        ->assertInertia(fn ($page) => $page->where('canViewBilling', true));
});

it('keeps admin-suspended organizations out of billing too', function () {
    $owner = User::factory()->create();
    $owner->organization->suspend('Abuse report');

    $this->actingAs($owner)->get(route('billing.index'))->assertForbidden();
});

it('changes plan with an invoice outside the trial', function () {
    $owner = User::factory()->create();
    $business = Package::where('slug', 'business')->firstOrFail();

    $this->actingAs($owner)
        ->post(route('billing.plan'), ['package_id' => $business->id, 'billing_cycle' => 'monthly'])
        ->assertSessionHas('success');

    $org = $owner->organization;
    expect(subscriptionOf($org)->package_id)->toBe($business->id)
        ->and(subscriptionOf($org)->status)->toBe('active')
        ->and(Subscription::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'cancelled')->count())->toBe(1)
        ->and((float) Invoice::withoutGlobalScopes()->where('organization_id', $org->id)->sole()->total)->toBe(19500.0);
});

it('keeps the trial running when changing plan during a trial', function () {
    $this->post('/register', [
        'organization_name' => 'Trial Co', 'name' => 'T', 'email' => 't@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ]);
    $owner = User::where('email', 't@example.com')->first();
    $business = Package::where('slug', 'business')->firstOrFail();

    $this->actingAs($owner)->post(route('billing.plan'), ['package_id' => $business->id, 'billing_cycle' => 'annual']);

    expect(subscriptionOf($owner->organization)->status)->toBe('trialing')
        ->and((float) subscriptionOf($owner->organization)->price)->toBe(210000.0)
        ->and(Invoice::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a downgrade below current user count', function () {
    $owner = User::factory()->create();
    $tiny = Package::factory()->create(['slug' => 'solo', 'max_users' => 1]);
    User::factory()->for($owner->organization)->role(Role::Agent)->create();

    $this->actingAs($owner)
        ->post(route('billing.plan'), ['package_id' => $tiny->id, 'billing_cycle' => 'monthly'])
        ->assertSessionHasErrors('package_id');
});

it('restricts plan changes to billing managers', function () {
    $agent = User::factory()->role(Role::Agent)->create();
    $business = Package::where('slug', 'business')->firstOrFail();

    $this->actingAs($agent)->get(route('billing.index'))->assertForbidden();
    $this->actingAs($agent)->post(route('billing.plan'), ['package_id' => $business->id, 'billing_cycle' => 'monthly'])->assertForbidden();
});

it('does not show another organization\'s invoice', function () {
    $mine = User::factory()->create();
    $other = User::factory()->create();
    subscriptionOf($other->organization)->forceFill(['current_period_end' => now()->subMinute()])->save();
    app(BillingService::class)->run();
    $foreign = Invoice::withoutGlobalScopes()->where('organization_id', $other->organization_id)->sole();

    $this->actingAs($mine)->get(route('billing.invoices.show', $foreign->id))->assertNotFound();
    $this->actingAs($other)->get(route('billing.invoices.show', $foreign->id))->assertOk()->assertSee($foreign->number);
});

it('lets platform admins edit package pricing and features', function () {
    $admin = User::factory()->platformAdmin()->create();
    $starter = Package::where('slug', 'starter')->firstOrFail();

    $this->actingAs($admin)->put(route('platform.packages.update', $starter), [
        'name' => 'Starter', 'slug' => 'starter', 'currency' => 'LKR', 'monthly_price' => 8000,
        'max_users' => 5, 'trial_days' => 30, 'is_active' => true, 'features' => ['ivr.basic', 'recordings'],
    ])->assertSessionHas('success');

    $starter->refresh();
    expect((float) $starter->monthly_price)->toBe(8000.0)
        ->and($starter->max_users)->toBe(5)
        ->and($starter->features()->pluck('feature')->sort()->values()->all())->toBe(['ivr.basic', 'recordings']);
});

it('numbers invoices sequentially', function () {
    $orgs = User::factory()->count(2)->create()->map->organization;
    $orgs->each(fn ($o) => subscriptionOf($o)->forceFill(['current_period_end' => now()->subMinute()])->save());

    app(BillingService::class)->run();

    $numbers = Invoice::withoutGlobalScopes()->orderBy('id')->pluck('number')->all();
    expect($numbers[0])->toEndWith('00001')->and($numbers[1])->toEndWith('00002');
});
