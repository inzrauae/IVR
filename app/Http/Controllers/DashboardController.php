<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
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
}
