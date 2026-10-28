<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Extension;
use App\Tenancy\Tenant;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Tenant $tenant): Response
    {
        $organization = $tenant->get()->load(['currentSubscription.package', 'pbxServer']);
        $subscription = $organization->currentSubscription;

        return Inertia::render('Dashboard', [
            'plan' => $subscription ? [
                'name' => $subscription->package->name,
                'status' => $subscription->status,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'price' => $subscription->price,
                'currency' => $subscription->currency,
            ] : null,
            'seats' => [
                'used' => $organization->users()->count(),
                'limit' => $organization->userLimit(),
            ],
            'extensions' => [
                'registered' => Extension::where('registered', true)->count(),
                'total' => Extension::count(),
            ],
            'calls' => $this->callActivity($organization->timezone ?: 'UTC'),
            'pbx' => $organization->pbxServer ? [
                'name' => $organization->pbxServer->name,
                'status' => $organization->pbxServer->status,
                'checked_at' => $organization->pbxServer->last_health_check_at?->toIso8601String(),
            ] : null,
            'recentActivity' => AuditLog::where('organization_id', $organization->id)
                ->with('user:id,name')
                ->latest('id')
                ->limit(8)
                ->get(['id', 'user_id', 'action', 'created_at']),
        ]);
    }

    /**
     * Today's call figures in the organization's timezone.
     *
     * @return array<string, int|null>
     */
    private function callActivity(string $timezone): array
    {
        $today = Call::where('started_at', '>=', now($timezone)->startOfDay()->utc());
        $answered = (clone $today)->whereNotNull('answered_at')->get(['started_at', 'answered_at']);

        return [
            'live' => Call::live()->count(),
            'inbound' => (clone $today)->where('direction', Call::DIRECTION_INBOUND)->count(),
            'outbound' => (clone $today)->where('direction', Call::DIRECTION_OUTBOUND)->count(),
            'missed' => (clone $today)->where('status', Call::STATUS_MISSED)->count(),
            'available' => Extension::where('registered', true)->where('do_not_disturb', false)->where('status', Extension::STATUS_ACTIVE)->count(),
            'avg_ring' => $answered->isEmpty() ? null : (int) round($answered->avg(fn ($c) => $c->started_at->diffInSeconds($c->answered_at))),
        ];
    }
}
