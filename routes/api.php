<?php

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Versioned, tenant-scoped public API (reference: Integrations page).
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

    Route::get('calls', [ApiController::class, 'calls']);
    Route::get('calls/{call}', [ApiController::class, 'call'])->whereNumber('call');
    Route::get('extensions', [ApiController::class, 'extensions']);
    Route::get('contacts', [ApiController::class, 'contacts']);
    Route::post('contacts', [ApiController::class, 'storeContact']);
    Route::delete('contacts/{contact}', [ApiController::class, 'destroyContact'])->whereNumber('contact');
});
