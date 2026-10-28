<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PbxServer;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\Tenant;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Tenant $tenant): Response
    {
        $stats = $tenant->bypass(fn () => [
            'organizations' => [
                'total' => Organization::count(),
                'active' => Organization::where('status', Organization::STATUS_ACTIVE)->count(),
                'trial' => Organization::where('status', Organization::STATUS_TRIAL)->count(),
                'suspended' => Organization::where('status', Organization::STATUS_SUSPENDED)->count(),
            ],
            'users' => User::whereNotNull('organization_id')->count(),
            // MRR counts paying subscriptions only; trials are excluded.
            'mrr' => round(Subscription::where('status', Subscription::STATUS_ACTIVE)->get()->sum(fn (Subscription $s) => $s->monthlyValue()), 2),
            'pbx' => [
                'total' => PbxServer::count(),
                'online' => PbxServer::where('status', PbxServer::STATUS_ONLINE)->count(),
                'offline' => PbxServer::where('status', PbxServer::STATUS_OFFLINE)->count(),
            ],
        ]);

        return Inertia::render('platform/Dashboard', [
            'stats' => $stats,
            'currency' => config('app.billing_currency', 'LKR'),
            'packages' => Package::withCount(['features'])->orderBy('monthly_price')->get(),
            'recentActivity' => AuditLog::with(['user:id,name', 'organization:id,name'])
                ->latest('id')
                ->limit(10)
                ->get(['id', 'user_id', 'organization_id', 'action', 'created_at']),
        ]);
    }
}
