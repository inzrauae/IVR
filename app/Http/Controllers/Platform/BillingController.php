<?php

namespace App\Http\Controllers\Platform;

use App\Billing\BillingService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->value() ?: 'open';

        return $this->tenant->bypass(fn () => Inertia::render('platform/Invoices', [
            'invoices' => Invoice::query()
                ->with('organization:id,name')
                ->when($status === 'overdue', fn ($q) => $q->where('status', Invoice::STATUS_OPEN)->where('due_at', '<', now()))
                ->when($status !== 'overdue' && $status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest('issued_at')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Invoice $i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'organization' => $i->organization?->name,
                    'status' => $i->isOverdue() ? 'overdue' : $i->status,
                    'total' => $i->total,
                    'balance' => $i->status === Invoice::STATUS_OPEN ? $i->balance() : 0,
                    'currency' => $i->currency,
                    'issued_at' => $i->issued_at->toIso8601String(),
                    'due_at' => $i->due_at->toIso8601String(),
                ]),
            'totals' => [
                'outstanding' => round((float) Invoice::where('status', Invoice::STATUS_OPEN)->sum('total') - (float) Payment::whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_OPEN))->sum('amount'), 2),
                'overdue_count' => Invoice::where('status', Invoice::STATUS_OPEN)->where('due_at', '<', now())->count(),
                'collected_this_month' => (float) Payment::where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
            ],
            'methods' => Payment::METHODS,
            'filters' => ['status' => $status],
        ]));
    }

    public function recordPayment(Request $request, int $invoice, BillingService $billing): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $record = $this->tenant->bypass(fn () => Invoice::findOrFail($invoice));
        $organization = Organization::findOrFail($record->organization_id);

        // Payments are tenant-owned, so they are written in the invoice's organization context.
        $this->tenant->runAs($organization, fn () => $billing->recordPayment(
            $record,
            (float) $validated['amount'],
            $validated['method'],
            $validated['reference'] ?? null,
            $request->user(),
            isset($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : null,
        ));

        return back()->with('success', "Payment recorded against {$record->number}.");
    }

    public function void(int $invoice, BillingService $billing): RedirectResponse
    {
        $record = $this->tenant->bypass(fn () => Invoice::findOrFail($invoice));
        $billing->voidInvoice($record);

        return back()->with('success', "{$record->number} voided.");
    }

    public function run(BillingService $billing): RedirectResponse
    {
        $stats = $billing->run();

        return back()->with('success', "Billing run complete: {$stats['invoiced']} invoiced, {$stats['past_due']} past due, {$stats['suspended']} suspended.");
    }
}
