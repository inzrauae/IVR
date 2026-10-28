<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's organization as the tenant for this request and blocks
 * access while the organization or user is suspended. Must run after authentication.
 */
class SetTenantContext
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * @param  string|null  $mode  "billing" lets organizations suspended for non-payment reach billing pages so they can settle up.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();
        $organization = $user?->organization;

        if (! $organization) {
            if ($user?->isPlatformAdmin() && ! $request->expectsJson()) {
                return redirect()->route('platform.dashboard');
            }

            abort(403, 'Your account is not attached to an organization.');
        }

        $billingException = $mode === 'billing' && $organization->suspension_source === Organization::SUSPENDED_BY_BILLING;

        if (($organization->isSuspended() && ! $billingException) || $user->status !== User::STATUS_ACTIVE) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, 'This account is suspended.');
            }

            return Inertia::render('OrganizationSuspended', [
                'reason' => $user->status !== User::STATUS_ACTIVE ? 'Your user account has been disabled.' : $organization->suspension_reason,
                'canViewBilling' => $organization->suspension_source === Organization::SUSPENDED_BY_BILLING
                    && $user->status === User::STATUS_ACTIVE
                    && $user->can('billing.view'),
            ])->toResponse($request)->setStatusCode(403);
        }

        $allowlist = $organization->setting('security.ip_allowlist');

        if ($allowlist && ! IpUtils::checkIp($request->ip(), $allowlist)) {
            abort(403, 'Access from your IP address is not allowed for this organization.');
        }

        $this->tenant->set($organization);

        return $next($request);
    }
}
