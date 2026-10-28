<?php

namespace App\Http\Controllers;

use App\Authorization\Role;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $organization = $this->tenant->get();

        return Inertia::render('users/Index', [
            'users' => $organization->users()
                ->with('roles:id,name')
                ->orderBy('name')
                ->get(['id', 'organization_id', 'name', 'email', 'status', 'last_login_at', 'created_at'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'role' => $user->roles->first()?->name,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'can' => [
                        // Self-edits are always rejected by update(), so don't offer them.
                        'update' => $request->user()->isNot($user) && $request->user()->can('update', $user),
                        'delete' => $request->user()->can('delete', $user),
                    ],
                ]),
            'roles' => $this->assignableRoles($request->user()),
            'seats' => ['used' => $organization->users()->count(), 'limit' => $organization->userLimit()],
            'can' => ['create' => $request->user()->can('create', User::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $organization = $this->tenant->get();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', Password::defaults()],
            'role' => ['required', Rule::in(array_keys($this->assignableRoles($request->user())))],
        ]);

        if (! $organization->hasUserCapacity()) {
            return back()->with('error', 'Your plan\'s user limit has been reached. Upgrade your package to add more users.');
        }

        $user = new User($validated);
        $user->organization()->associate($organization);
        $user->save();
        $user->assignRole($validated['role']);

        Audit::record('user.created', $user, ['role' => $validated['role']]);

        return back()->with('success', "{$user->name} has been added.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureTenantMember($user);
        Gate::authorize('update', $user);

        $validated = $request->validate([
            'role' => ['required', Rule::in(array_keys($this->assignableRoles($request->user())))],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        if ($user->is($request->user()) && ($validated['status'] !== User::STATUS_ACTIVE || $validated['role'] !== $user->primaryRole())) {
            return back()->with('error', 'You cannot change your own role or disable your own account.');
        }

        $changes = ['from_role' => $user->primaryRole(), 'to_role' => $validated['role'], 'status' => $validated['status']];

        $user->forceFill(['status' => $validated['status']])->save();
        $user->syncRoles([$validated['role']]);

        Audit::record('user.updated', $user, $changes);

        return back()->with('success', "{$user->name} has been updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensureTenantMember($user);
        Gate::authorize('delete', $user);

        Audit::record('user.removed', $user, ['email' => $user->email]);
        $user->delete();

        return back()->with('success', "{$user->name} has been removed.");
    }

    /**
     * Users of other organizations are reported as missing rather than forbidden.
     */
    private function ensureTenantMember(User $user): void
    {
        abort_unless($user->belongsToOrganization($this->tenant->get()), 404);
    }

    /**
     * Roles the actor may hand out. Only owners can create further owners.
     *
     * @return array<string, string>
     */
    private function assignableRoles(User $actor): array
    {
        $roles = Role::options();

        if (! $actor->hasRole(Role::Owner->value)) {
            unset($roles[Role::Owner->value]);
        }

        return $roles;
    }
}
