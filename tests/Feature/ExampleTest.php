<?php

use App\Models\User;

it('sends guests from the home page to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends organization users from the home page to their dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('dashboard'));
});

it('sends platform admins from the home page to the platform console', function () {
    $this->actingAs(User::factory()->platformAdmin()->create())->get('/')->assertRedirect(route('platform.dashboard'));
});
