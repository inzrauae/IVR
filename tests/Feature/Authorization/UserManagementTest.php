<?php

use App\Authorization\Role;
use App\Models\AuditLog;
use App\Models\User;

it('lets an owner add a user within the plan limit', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('users.store'), [
            'name' => 'New Agent',
            'email' => 'agent@example.com',
            'password' => 'Str0ng-password!',
            'role' => Role::Agent->value,
        ])
        ->assertSessionHas('success');

    $agent = User::where('email', 'agent@example.com')->firstOrFail();

    expect($agent->organization_id)->toBe($owner->organization_id)
        ->and($agent->hasRole('agent'))->toBeTrue()
        ->and(AuditLog::where('action', 'user.created')->where('organization_id', $owner->organization_id)->exists())->toBeTrue();
});

it('enforces the package user limit', function () {
    // Starter allows 3 users.
    $owner = User::factory()->create();
    User::factory()->count(2)->for($owner->organization)->role(Role::Agent)->create();

    $this->actingAs($owner)
        ->post(route('users.store'), [
            'name' => 'One Too Many',
            'email' => 'extra@example.com',
            'password' => 'Str0ng-password!',
            'role' => Role::Agent->value,
        ])
        ->assertSessionHas('error');

    expect(User::where('email', 'extra@example.com')->exists())->toBeFalse();
});

it('forbids agents from managing users', function () {
    $agent = User::factory()->role(Role::Agent)->create();

    $this->actingAs($agent)->get(route('users.index'))->assertForbidden();
    $this->actingAs($agent)->post(route('users.store'), [])->assertForbidden();
});

it('prevents admins from assigning or modifying owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->for($owner->organization)->role(Role::Admin)->create();

    $this->actingAs($admin)
        ->patch(route('users.update', $owner), ['role' => 'agent', 'status' => 'active'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Sneaky Owner',
            'email' => 'sneaky@example.com',
            'password' => 'Str0ng-password!',
            'role' => Role::Owner->value,
        ])
        ->assertSessionHasErrors('role');
});

it('does not let users demote or remove themselves', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->patch(route('users.update', $owner), ['role' => 'agent', 'status' => 'active'])
        ->assertSessionHas('error');

    $this->actingAs($owner)->delete(route('users.destroy', $owner))->assertForbidden();

    expect($owner->fresh()->hasRole('owner'))->toBeTrue();
});

it('only shows sidebar modules the user is permitted to see', function () {
    $billing = User::factory()->role(Role::BillingManager)->create();

    $this->actingAs($billing)
        ->get(route('dashboard'))
        ->assertInertia(function ($page) {
            $titles = collect($page->toArray()['props']['navigation'])->pluck('items')->flatten(1)->pluck('title');

            expect($titles)->toContain('Billing')
                ->not->toContain('SIP Trunks')
                ->not->toContain('Users & Permissions');
        });
});

it('marks unbuilt modules as unavailable and still enforces their permission', function () {
    $owner = User::factory()->create();
    $agent = User::factory()->role(Role::Agent)->create();

    $this->actingAs($owner)
        ->get(route('trunks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/Unavailable')->where('module.title', 'SIP Trunks'));

    $this->actingAs($agent)->get(route('trunks.index'))->assertForbidden();
});
