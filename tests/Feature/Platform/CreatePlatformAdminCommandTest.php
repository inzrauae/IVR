<?php

use App\Models\User;

it('creates a platform admin from the console', function () {
    $this->artisan('platform:create-admin', ['--name' => 'Ops', '--email' => 'ops@example.com'])
        ->expectsQuestion('Password (min 12 characters)', 'Very-Strong-Pass-123')
        ->assertSuccessful();

    $user = User::where('email', 'ops@example.com')->firstOrFail();

    expect($user->isPlatformAdmin())->toBeTrue()
        ->and($user->organization_id)->toBeNull();
});

it('rejects weak passwords', function () {
    $this->artisan('platform:create-admin', ['--name' => 'Ops', '--email' => 'ops@example.com'])
        ->expectsQuestion('Password (min 12 characters)', 'short')
        ->assertFailed();

    expect(User::where('email', 'ops@example.com')->exists())->toBeFalse();
});
