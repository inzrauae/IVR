<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Billing\BillingService;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Payment;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::BILLING_VIEW);

        $organization = $this->tenant->get()->load('currentSubscription.package.features');
        $subscription = $organization->currentSubscription;

        return Inertia::render('billing/Index', [
            'subscription' => $subscription ? [
                'package' => $subscription->package->only(['id', 'name', 'slug', 'max_users', 'max_extensions', 'max_concurrent_calls', 'recording_storage_mb']),
                'features' => $subscription->package->features->pluck('feature'),
                'status' => $subscription->status,
                'billing_cycle' => $subscription->billing_cycle,
                'price' => $subscription->price,
                'currency' => $subscription->currency,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ] : null,
            'usage' => [
                'users' => $organization->users()->count(),
            ],
            'packages' => Package::where('is_active', true)->orderBy('monthly_price')
                ->get(['id', 'name', 'slug', 'description', 'currency', 'monthly_price', 'annual_price', 'max_users', 'max_extensions', 'max_concurrent_calls']),
            'invoices' => Invoice::latest('issued_at')->limit(50)->get()->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'status' => $i->isOverdue() ? 'overdue' : $i->status,
                'total' => $i->total,
                'currency' => $i->currency,
                'issued_at' => $i->issued_at->toIso8601String(),
                'due_at' => $i->due_at->toIso8601String(),
            ]),
            'payments' => Payment::with('invoice:id,number')->latest('paid_at')->limit(50)->get()->map(fn (Payment $p) => [
                'id' => $p->id,
                'invoice' => $p->invoice?->number,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'method' => Payment::METHODS[$p->method] ?? $p->method,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at->toIso8601String(),
            ]),
            'billingEmail' => $organization->billing_email,
            'seller' => config('billing.seller'),
            'canManage' => $request->user()->can(Permission::BILLING_MANAGE),
        ]);
    }

    public function changePlan(Request $request, BillingService $billing): RedirectResponse
    {
        Gate::authorize(Permission::BILLING_MANAGE);

        $validated = $request->validate([
            'package_id' => ['required', Rule::exists(Package::class, 'id')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])],
        ]);

        $organization = $this->tenant->get();
        $package = Package::findOrFail($validated['package_id']);
        $current = $organization->currentSubscription;

        if ($current && $current->package_id === $package->id && $current->billing_cycle === $validated['billing_cycle']) {
            return back()->with('error', 'You are already on this plan.');
        }

        $subscription = $billing->changePlan($organization, $package, $validated['billing_cycle']);

        return back()->with('success', $subscription->status === 'trialing'
            ? "Switched to {$package->name}. Your trial continues."
            : "Switched to {$package->name}. An invoice has been issued.");
    }

    public function updateContact(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::BILLING_MANAGE);

        $validated = $request->validate(['billing_email' => ['nullable', 'email', 'max:255']]);

        $organization = $this->tenant->get();
        $organization->forceFill($validated)->save();
        Audit::record('billing.contact_updated', $organization);

        return back()->with('success', 'Billing contact saved.');
    }

    /**
     * Printable invoice. Resolved through the tenant scope, so other organizations' invoices 404.
     */
    public function showInvoice(int $invoice): \Illuminate\Contracts\View\View
    {
        Gate::authorize(Permission::BILLING_VIEW);

        $record = Invoice::with('payments')->findOrFail($invoice);

        return view('billing.invoice', [
            'invoice' => $record,
            'organization' => $this->tenant->get(),
            'seller' => config('billing.seller'),
        ]);
    }
}
