<?php

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\Tenant;

it('returns no tenant-owned rows when no tenant context is set', function () {
    Organization::factory()->count(2)->create();

    expect(Subscription::count())->toBe(0)
        ->and(app(Tenant::class)->bypass(fn () => Subscription::count()))->toBe(2);
});

it('scopes tenant-owned models to the current organization', function () {
    [$a, $b] = Organization::factory()->count(2)->create();

    app(Tenant::class)->runAs($a, function () use ($a) {
        expect(Subscription::pluck('organization_id')->unique()->all())->toBe([$a->id]);
    });

    app(Tenant::class)->runAs($b, function () use ($b) {
        expect(Subscription::pluck('organization_id')->unique()->all())->toBe([$b->id]);
    });
});

it('refuses to create tenant-owned records without an organization context', function () {
    $package = \App\Models\Package::first();

    Subscription::create([
        'package_id' => $package->id,
        'status' => Subscription::STATUS_ACTIVE,
        'price' => 1,
        'currency' => 'LKR',
    ]);
})->throws(LogicException::class);

it('stamps new tenant-owned records with the current organization', function () {
    $org = Organization::factory()->create();
    $package = \App\Models\Package::first();

    $subscription = app(Tenant::class)->runAs($org, fn () => Subscription::create([
        'package_id' => $package->id,
        'status' => Subscription::STATUS_ACTIVE,
        'price' => 1,
        'currency' => 'LKR',
    ]));

    expect($subscription->organization_id)->toBe($org->id);
});

it('only lists users from the actor\'s own organization', function () {
    $owner = User::factory()->create();
    $colleague = User::factory()->for($owner->organization)->create(['name' => 'Same Org Colleague']);
    $outsider = User::factory()->create(['name' => 'Other Org Person']);

    $this->actingAs($owner)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('users/Index')
            ->has('users', 2)
            ->where('users.0.name', fn ($name) => in_array($name, [$owner->name, $colleague->name]))
        )
        ->assertDontSee($outsider->email);
});

it('returns 404 when modifying a user in another organization', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();

    $this->actingAs($owner)
        ->patch(route('users.update', $outsider), ['role' => 'agent', 'status' => 'active'])
        ->assertNotFound();

    $this->actingAs($owner)
        ->delete(route('users.destroy', $outsider))
        ->assertNotFound();

    expect($outsider->fresh())->not->toBeNull()
        ->and($outsider->fresh()->hasRole('owner'))->toBeTrue();
});

it('blocks users of suspended organizations', function () {
    $user = User::factory()->for(Organization::factory()->suspended('Invoice overdue'))->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('OrganizationSuspended')->where('reason', 'Invoice overdue'));
});

it('blocks disabled users', function () {
    $user = User::factory()->disabled()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
});
