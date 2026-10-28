<?php

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;

it('creates an organization, owner and trial subscription on registration', function () {
    $this->post('/register', [
        'organization_name' => 'Lanka Logistics',
        'name' => 'Nimal Perera',
        'email' => 'nimal@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'nimal@example.com')->firstOrFail();
    $org = $user->organization;

    expect($org->name)->toBe('Lanka Logistics')
        ->and($org->status)->toBe(Organization::STATUS_TRIAL)
        ->and($user->hasRole('owner'))->toBeTrue()
        ->and($org->currentSubscription->status)->toBe(Subscription::STATUS_TRIALING)
        ->and($org->currentSubscription->package->slug)->toBe('starter')
        ->and($org->currentSubscription->trial_ends_at->isFuture())->toBeTrue();
});

it('requires an organization name to register', function () {
    $this->post('/register', [
        'name' => 'No Org',
        'email' => 'noorg@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('organization_name');

    $this->assertGuest();
});

it('issues unique slugs for organizations with the same name', function () {
    foreach (['a@example.com', 'b@example.com'] as $email) {
        auth()->logout();
        $this->post('/register', [
            'organization_name' => 'Same Name',
            'name' => 'Person',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    expect(Organization::where('name', 'Same Name')->pluck('slug')->unique())->toHaveCount(2);
});
