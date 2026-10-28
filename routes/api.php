<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Versioned, tenant-scoped public API. Resource endpoints arrive with their modules.
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
    Route::get('me', function (Request $request) {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->primaryRole(),
            'organization' => $user->organization->only(['id', 'name', 'slug']),
        ];
    });
});
