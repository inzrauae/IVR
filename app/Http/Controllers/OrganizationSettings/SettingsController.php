<?php

namespace App\Http\Controllers\OrganizationSettings;

use App\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BusinessHour;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use App\Rules\IpOrCidr;
use App\Support\Audit;
use App\Tenancy\Tenant;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    public const SECTIONS = ['general', 'business-hours', 'calls', 'notifications', 'security', 'diagnostics'];

    public const CURRENCIES = ['LKR', 'USD', 'EUR', 'GBP', 'INR', 'AUD', 'SGD', 'AED'];

    public function __construct(private readonly Tenant $tenant) {}

    public function show(Request $request, string $section = 'general'): Response
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        Gate::authorize(Permission::SETTINGS_VIEW);

        $organization = $this->tenant->get();

        $data = match ($section) {
            'general' => [
                'organization' => [
                    ...$organization->only(['name', 'timezone', 'locale', 'currency', 'contact_email', 'contact_phone', 'address']),
                    'date_format' => $organization->setting('general.date_format'),
                    'logo_url' => $organization->logoUrl(),
                ],
                'options' => [
                    'timezones' => DateTimeZone::listIdentifiers(),
                    'currencies' => self::CURRENCIES,
                    'languages' => OrganizationSettings::LANGUAGES,
                    'dateFormats' => OrganizationSettings::DATE_FORMATS,
                ],
            ],
            'business-hours' => [
                'hours' => $this->businessHours($organization),
                'holidays' => $organization->holidays()->get(['id', 'name', 'date', 'recurring'])
                    ->map(fn ($h) => ['id' => $h->id, 'name' => $h->name, 'date' => $h->date->toDateString(), 'recurring' => $h->recurring]),
                'timezone' => $organization->timezone,
            ],
            'calls' => [
                'settings' => $organization->setting('call'),
                'recordingModes' => OrganizationSettings::RECORDING_MODES,
            ],
            'notifications' => [
                'settings' => $organization->setting('notifications'),
            ],
            'security' => [
                'settings' => $organization->setting('security'),
                'currentIp' => $request->ip(),
                'tokens' => $request->user()->tokens()->latest()->get(['id', 'name', 'last_used_at', 'created_at']),
                'sessions' => $this->sessions($request),
                'sessionsSupported' => config('session.driver') === 'database',
                'logins' => AuditLog::where('organization_id', $organization->id)
                    ->where('action', 'auth.login')
                    ->with('user:id,name')
                    ->latest('id')
                    ->limit(15)
                    ->get(['id', 'user_id', 'ip_address', 'user_agent', 'created_at']),
            ],
            'diagnostics' => [
                'diagnostics' => $this->diagnostics($organization),
            ],
        };

        return Inertia::render('organization-settings/'.str($section)->studly(), [
            ...$data,
            'section' => $section,
            'canManage' => $request->user()->can(Permission::SETTINGS_MANAGE),
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'locale' => ['required', Rule::in(array_keys(OrganizationSettings::LANGUAGES))],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'date_format' => ['required', Rule::in(array_keys(OrganizationSettings::DATE_FORMATS))],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $organization = $this->tenant->get();
        $organization->fill(collect($validated)->except('date_format')->all())->save();
        $organization->updateSettingsSection('general', ['date_format' => $validated['date_format']]);

        Audit::record('settings.general_updated', $organization, ['fields' => array_keys($organization->getChanges())]);

        return back()->with('success', 'General settings saved.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000']]);

        $organization = $this->tenant->get();
        $old = $organization->logo_path;

        $path = $request->file('logo')->store("organizations/{$organization->id}", 'local');
        $organization->forceFill(['logo_path' => $path])->save();

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        Audit::record('settings.logo_updated', $organization);

        return back()->with('success', 'Logo updated.');
    }

    public function deleteLogo(): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $organization = $this->tenant->get();

        if ($organization->logo_path) {
            Storage::disk('local')->delete($organization->logo_path);
            $organization->forceFill(['logo_path' => null])->save();
            Audit::record('settings.logo_removed', $organization);
        }

        return back()->with('success', 'Logo removed.');
    }

    /**
     * Logos live on the private disk and are streamed only to members of the organization.
     */
    public function logo(): StreamedResponse
    {
        $organization = $this->tenant->get();

        abort_unless($organization->logo_path && Storage::disk('local')->exists($organization->logo_path), 404);

        return Storage::disk('local')->response($organization->logo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function updateCalls(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $validated = $request->validate([
            'default_caller_id' => ['nullable', 'string', 'regex:/^\+?[0-9]{4,15}$/'],
            'ring_timeout' => ['required', 'integer', 'between:5,120'],
            'max_call_minutes' => ['required', 'integer', 'between:1,1440'],
            'recording_mode' => ['required', Rule::in(array_keys(OrganizationSettings::RECORDING_MODES))],
            'recording_announcement' => ['required', 'boolean'],
            'allow_international' => ['required', 'boolean'],
            'block_premium' => ['required', 'boolean'],
            'hold_music' => ['required', Rule::in(['default', 'none'])],
        ], [
            'default_caller_id.regex' => 'Use digits only, optionally starting with + (e.g. +94112345678).',
        ]);

        $organization = $this->tenant->get();
        $before = $organization->setting('call');
        $organization->updateSettingsSection('call', $validated);

        Audit::record('settings.calls_updated', $organization, ['changed' => array_keys(array_diff_assoc(array_map('strval', $validated), array_map('strval', $before)))]);

        return back()->with('success', 'Call settings saved.');
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $keys = array_keys(OrganizationSettings::defaults()['notifications']);
        $rules = [];
        foreach ($keys as $key) {
            $rules[$key] = ['present', 'array', 'max:10'];
            $rules["{$key}.*"] = ['email', 'max:255', 'distinct'];
        }

        $validated = $request->validate($rules);

        $organization = $this->tenant->get();
        $organization->updateSettingsSection('notifications', $validated);
        Audit::record('settings.notifications_updated', $organization);

        return back()->with('success', 'Notification settings saved.');
    }

    public function updateSecurity(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $validated = $request->validate([
            'ip_allowlist' => ['present', 'array', 'max:50'],
            'ip_allowlist.*' => ['string', 'distinct', new IpOrCidr],
        ]);

        $list = array_values($validated['ip_allowlist']);

        // Refuse a list that would lock out the person saving it.
        if ($list && ! IpUtils::checkIp($request->ip(), $list)) {
            return back()->withErrors(['ip_allowlist' => "Your current IP ({$request->ip()}) must be in the allowlist, or you would lock yourself out."]);
        }

        $organization = $this->tenant->get();
        $organization->updateSettingsSection('security', ['ip_allowlist' => $list]);
        Audit::record('settings.ip_allowlist_updated', $organization, ['entries' => count($list)]);

        return back()->with('success', $list ? 'IP allowlist saved and enforced.' : 'IP allowlist cleared; access is allowed from any IP.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function businessHours(Organization $organization): array
    {
        $saved = $organization->businessHours()->get()->keyBy('day_of_week');

        return collect(range(0, 6))->map(fn (int $day) => [
            'day_of_week' => $day,
            'day' => BusinessHour::DAYS[$day],
            'is_open' => (bool) ($saved[$day]->is_open ?? ($day >= 1 && $day <= 5)),
            'opens_at' => substr($saved[$day]->opens_at ?? '09:00', 0, 5),
            'closes_at' => substr($saved[$day]->closes_at ?? '17:00', 0, 5),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => [
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'last_active_at' => date(DATE_ATOM, $s->last_activity),
                'is_current' => $s->id === $request->session()->getId(),
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnostics(Organization $organization): array
    {
        $server = $organization->pbxServer;

        return [
            'pbx' => $server ? [
                'name' => $server->name,
                'driver' => $server->driver,
                'status' => $server->status,
                'version' => $server->last_health_payload['version'] ?? null,
                'error' => $server->last_health_payload['error'] ?? null,
                'checked_at' => $server->last_health_check_at?->toIso8601String(),
            ] : null,
            'queue' => [
                'connection' => config('queue.default'),
                'pending' => rescue(fn () => DB::table('jobs')->count(), null, false),
                'failed' => rescue(fn () => DB::table('failed_jobs')->count(), null, false),
            ],
            'app' => [
                'environment' => app()->environment(),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'timezone' => $organization->timezone,
                'server_time' => now()->toIso8601String(),
            ],
        ];
    }
}
