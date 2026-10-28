<?php

namespace App\Http\Controllers\OrganizationSettings;

use App\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Personal API tokens and active browser sessions for the signed-in user.
 */
class AccessController extends Controller
{
    public function storeToken(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $token = $request->user()->createToken($validated['name']);
        Audit::record('api_token.created', $request->user(), ['name' => $validated['name']]);

        // The plain-text token is shown exactly once.
        return back()->with('success', 'API token created. Copy it now — it will not be shown again.')
            ->with('token', $token->plainTextToken);
    }

    public function destroyToken(Request $request, int $token): RedirectResponse
    {
        $record = $request->user()->tokens()->findOrFail($token);
        $record->delete();
        Audit::record('api_token.revoked', $request->user(), ['name' => $record->name]);

        return back()->with('success', "Token \"{$record->name}\" revoked.");
    }

    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        abort_unless(config('session.driver') === 'database', 404);

        $request->validate(['password' => ['required', 'current_password']]);

        $count = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        Audit::record('auth.sessions_revoked', $request->user(), ['count' => $count]);

        return back()->with('success', $count ? "Signed out of {$count} other session(s)." : 'No other sessions were active.');
    }
}
