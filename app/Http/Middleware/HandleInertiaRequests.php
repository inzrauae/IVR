<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        /** @var User|null $user */
        $user = $request->user();

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user,
                'role' => fn () => $user?->primaryRole(),
                'is_platform_admin' => (bool) $user?->isPlatformAdmin(),
                'permissions' => fn () => $user ? $user->getAllPermissions()->pluck('name') : [],
            ],
            'organization' => fn () => $user?->organization?->only(['id', 'name', 'slug', 'status']),
            'navigation' => fn () => $user ? $this->navigation($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'token' => fn () => $request->session()->get('token'),
                'credentials' => fn () => $request->session()->get('credentials'),
            ],
        ]);
    }

    /**
     * Sidebar modules the user is allowed to see, grouped as configured.
     *
     * @return list<array<string, mixed>>
     */
    private function navigation(User $user): array
    {
        if ($user->isPlatformAdmin() && ! $user->organization_id) {
            return [[
                'group' => 'Platform',
                'items' => [
                    ['title' => 'Overview', 'href' => route('platform.dashboard', absolute: false), 'icon' => 'Gauge', 'available' => true],
                    ['title' => 'Organizations', 'href' => route('platform.organizations.index', absolute: false), 'icon' => 'Building2', 'available' => true],
                    ['title' => 'Invoices', 'href' => route('platform.invoices.index', absolute: false), 'icon' => 'Receipt', 'available' => true],
                    ['title' => 'Packages', 'href' => route('platform.packages.index', absolute: false), 'icon' => 'Package', 'available' => true],
                    ['title' => 'PBX Servers', 'href' => route('platform.pbx-servers.index', absolute: false), 'icon' => 'Server', 'available' => true],
                ],
            ]];
        }

        return collect(config('modules'))
            ->filter(fn (array $module) => $user->can($module['permission']))
            ->groupBy('group')
            ->map(fn ($modules, $group) => [
                'group' => $group,
                'items' => $modules->map(fn (array $module) => [
                    'title' => $module['title'],
                    'href' => route($module['route'], absolute: false),
                    'icon' => $module['icon'],
                    'available' => $module['available'],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
