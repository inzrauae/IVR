<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Audit;
use App\Support\Webhooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webhooks plus the REST API reference. API tokens are managed under Settings → Security.
 */
class IntegrationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::INTEGRATIONS_VIEW);

        $webhooks = Webhook::orderBy('id')->get();
        $deliveries = WebhookDelivery::whereIn('webhook_id', $webhooks->pluck('id'))->latest('id')->limit(25)->get();

        return Inertia::render('integrations/Index', [
            'webhooks' => $webhooks->map(fn (Webhook $w) => [
                ...$w->only(['id', 'url', 'events', 'active']),
                'failing' => $deliveries->where('webhook_id', $w->id)->first()?->error !== null,
            ]),
            'deliveries' => $deliveries->map(fn (WebhookDelivery $d) => [
                ...$d->only(['id', 'webhook_id', 'event', 'response_status', 'error', 'attempts']),
                'delivered_at' => $d->delivered_at?->toIso8601String(),
                'created_at' => $d->created_at->toIso8601String(),
            ]),
            'events' => Webhook::EVENTS,
            'secret' => session('webhook_secret'),
            'apiBase' => url('/api/v1'),
            'can' => [
                'manage' => $request->user()->can(Permission::INTEGRATIONS_MANAGE),
                'tokens' => $request->user()->can(Permission::SETTINGS_VIEW),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::INTEGRATIONS_MANAGE);

        $secret = 'whsec_'.Str::random(32);
        $webhook = Webhook::create([...$this->validated($request), 'secret' => $secret]);
        Audit::record('webhook.created', $webhook, ['url' => $webhook->url]);

        return back()->with('success', 'Webhook added. Copy the signing secret now; it is shown only once.')->with('webhook_secret', ['id' => $webhook->id, 'secret' => $secret]);
    }

    public function update(Request $request, int $webhook): RedirectResponse
    {
        Gate::authorize(Permission::INTEGRATIONS_MANAGE);

        $record = Webhook::findOrFail($webhook);
        $record->update($this->validated($request));
        Audit::record('webhook.updated', $record, ['url' => $record->url]);

        return back()->with('success', 'Webhook saved.');
    }

    public function rotate(int $webhook): RedirectResponse
    {
        Gate::authorize(Permission::INTEGRATIONS_MANAGE);

        $record = Webhook::findOrFail($webhook);
        $secret = 'whsec_'.Str::random(32);
        $record->update(['secret' => $secret]);
        Audit::record('webhook.secret_rotated', $record);

        return back()->with('success', 'New signing secret created. Update your receiver; the old one no longer works.')->with('webhook_secret', ['id' => $record->id, 'secret' => $secret]);
    }

    public function test(int $webhook): RedirectResponse
    {
        Gate::authorize(Permission::INTEGRATIONS_MANAGE);

        $record = Webhook::findOrFail($webhook);
        Webhooks::send($record, 'ping', ['message' => 'Test event from CloudPBX']);

        return back()->with('success', 'Test event queued. Its result appears under recent deliveries.');
    }

    public function destroy(int $webhook): RedirectResponse
    {
        Gate::authorize(Permission::INTEGRATIONS_MANAGE);

        $record = Webhook::findOrFail($webhook);
        Audit::record('webhook.deleted', $record, ['url' => $record->url]);
        $record->delete();

        return back()->with('success', 'Webhook deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'url' => ['required', 'url:https', 'max:500', function ($attribute, $value, $fail) {
                // Never let a tenant aim deliveries at this server or a private network.
                if (! Webhooks::isPublicUrl((string) $value)) {
                    $fail('The URL must point to a public internet address.');
                }
            }],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['distinct', Rule::in(array_keys(Webhook::EVENTS))],
            'active' => ['boolean'],
        ], ['url.url' => 'Use an https:// URL.']);

        return [...$validated, 'active' => (bool) ($validated['active'] ?? true)];
    }
}
