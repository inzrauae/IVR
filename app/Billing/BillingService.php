<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    /**
     * Issue an invoice for one billing period of a subscription.
     */
    public function invoiceSubscription(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): Invoice
    {
        $package = $subscription->package;
        $subtotal = round((float) $subscription->price, 2);
        $taxRate = (float) config('billing.tax_rate');
        $tax = round($subtotal * $taxRate / 100, 2);

        $lines = [[
            'description' => sprintf('%s plan (%s) · %s – %s', $package->name, $subscription->billing_cycle, $periodStart->toDateString(), $periodEnd->toDateString()),
            'quantity' => 1,
            'unit_price' => $subtotal,
            'amount' => $subtotal,
        ]];

        if ($tax > 0) {
            $lines[] = ['description' => config('billing.tax_label')." ({$taxRate}%)", 'quantity' => 1, 'unit_price' => $tax, 'amount' => $tax];
        }

        $invoice = Invoice::create([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'number' => $this->nextInvoiceNumber(),
            'status' => Invoice::STATUS_OPEN,
            'currency' => $subscription->currency,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
            'line_items' => $lines,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'issued_at' => now(),
            'due_at' => now()->addDays((int) config('billing.payment_terms_days')),
        ]);

        Audit::record('invoice.issued', $invoice, ['number' => $invoice->number, 'total' => $invoice->total], $subscription->organization);

        return $invoice;
    }

    /**
     * Record a payment. A fully paid invoice is closed, and an organization suspended for
     * non-payment is reactivated once nothing is overdue.
     */
    public function recordPayment(Invoice $invoice, float $amount, string $method, ?string $reference, ?User $recordedBy, ?Carbon $paidAt = null): Payment
    {
        if ($invoice->status !== Invoice::STATUS_OPEN) {
            throw ValidationException::withMessages(['amount' => 'Only open invoices can receive payments.']);
        }

        if ($amount <= 0 || $amount > $invoice->balance() + 0.001) {
            throw ValidationException::withMessages(['amount' => 'Amount must be between 0 and the outstanding balance of '.number_format($invoice->balance(), 2).'.']);
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $recordedBy, $paidAt) {
            $payment = Payment::create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'recorded_by' => $recordedBy?->id,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'method' => $method,
                'reference' => $reference,
                'paid_at' => $paidAt ?? now(),
            ]);

            $organization = Organization::findOrFail($invoice->organization_id);

            if ($invoice->balance() <= 0) {
                $invoice->forceFill(['status' => Invoice::STATUS_PAID, 'paid_at' => $payment->paid_at])->save();
                $this->settleAccount($organization);
            }

            Audit::record('payment.recorded', $payment, ['invoice' => $invoice->number, 'amount' => $amount, 'method' => $method], $organization);

            return $payment;
        });
    }

    public function voidInvoice(Invoice $invoice): void
    {
        if ($invoice->status !== Invoice::STATUS_OPEN || $invoice->payments()->exists()) {
            throw ValidationException::withMessages(['invoice' => 'Only unpaid open invoices can be voided.']);
        }

        $invoice->forceFill(['status' => Invoice::STATUS_VOID])->save();
        $this->settleAccount(Organization::findOrFail($invoice->organization_id));

        Audit::record('invoice.voided', $invoice, ['number' => $invoice->number], Organization::find($invoice->organization_id));
    }

    /**
     * Switch an organization to another package. Takes effect immediately; a paid plan change
     * outside a trial starts a new billing period with a fresh invoice (no proration).
     */
    public function changePlan(Organization $organization, Package $package, string $cycle): Subscription
    {
        $current = $organization->currentSubscription;

        if ($package->max_users !== null && $organization->users()->count() > $package->max_users) {
            throw ValidationException::withMessages([
                'package_id' => "{$package->name} allows {$package->max_users} users; remove users before switching.",
            ]);
        }

        return DB::transaction(function () use ($organization, $package, $cycle, $current) {
            $inTrial = $current?->status === Subscription::STATUS_TRIALING && $current->trial_ends_at?->isFuture();
            $price = $cycle === 'annual' ? ($package->annual_price ?? $package->monthly_price * 12) : $package->monthly_price;
            $start = now();
            $end = $inTrial ? $current->trial_ends_at : ($cycle === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth());

            $current?->forceFill(['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

            $subscription = Subscription::create([
                'organization_id' => $organization->id,
                'package_id' => $package->id,
                'status' => $inTrial ? Subscription::STATUS_TRIALING : Subscription::STATUS_ACTIVE,
                'billing_cycle' => $cycle,
                'price' => $price,
                'currency' => $package->currency,
                'trial_ends_at' => $inTrial ? $current->trial_ends_at : null,
                'current_period_start' => $start,
                'current_period_end' => $end,
            ]);

            if (! $inTrial) {
                $this->invoiceSubscription($subscription, $start, $end);
            }

            Audit::record('subscription.changed', $subscription, [
                'from' => $current?->package?->slug,
                'to' => $package->slug,
                'cycle' => $cycle,
            ], $organization);

            return $subscription->setRelation('package', $package);
        });
    }

    /**
     * Daily billing run: renew due subscriptions, flag overdue accounts, suspend after grace.
     *
     * @return array{invoiced: int, past_due: int, suspended: int}
     */
    public function run(): array
    {
        $stats = ['invoiced' => 0, 'past_due' => 0, 'suspended' => 0];

        Subscription::withoutGlobalScopes()
            ->with(['package', 'organization'])
            ->whereIn('status', Subscription::LIVE_STATUSES)
            ->where('current_period_end', '<=', now())
            ->each(function (Subscription $subscription) use (&$stats) {
                $start = $subscription->current_period_end->copy();
                $end = $subscription->billing_cycle === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth();

                DB::transaction(function () use ($subscription, $start, $end) {
                    $this->invoiceSubscription($subscription, $start, $end);

                    $subscription->forceFill([
                        'status' => $subscription->status === Subscription::STATUS_TRIALING ? Subscription::STATUS_ACTIVE : $subscription->status,
                        'current_period_start' => $start,
                        'current_period_end' => $end,
                    ])->save();

                    if ($subscription->organization->status === Organization::STATUS_TRIAL) {
                        $subscription->organization->forceFill(['status' => Organization::STATUS_ACTIVE])->save();
                    }
                });

                $stats['invoiced']++;
            });

        $overdue = Invoice::withoutGlobalScopes()
            ->where('status', Invoice::STATUS_OPEN)
            ->where('due_at', '<', now())
            ->get()
            ->groupBy('organization_id');

        foreach ($overdue as $organizationId => $invoices) {
            $organization = Organization::find($organizationId);

            if (! $organization) {
                continue;
            }

            $updated = Subscription::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update(['status' => Subscription::STATUS_PAST_DUE]);
            $stats['past_due'] += $updated;

            $oldest = $invoices->sortBy('due_at')->first();

            if (! $organization->isSuspended() && $oldest->due_at->copy()->addDays((int) config('billing.grace_days'))->isPast()) {
                $organization->suspend("Invoice {$oldest->number} is overdue. Access will be restored once it is paid.", Organization::SUSPENDED_BY_BILLING);
                Audit::record('organization.suspended', $organization, ['reason' => 'billing', 'invoice' => $oldest->number], $organization);
                $stats['suspended']++;
            }
        }

        return $stats;
    }

    /**
     * Clear past-due state and billing suspensions once no invoice is overdue.
     */
    private function settleAccount(Organization $organization): void
    {
        $stillOverdue = $organization->invoices()
            ->where('status', Invoice::STATUS_OPEN)
            ->where('due_at', '<', now())
            ->exists();

        if ($stillOverdue) {
            return;
        }

        Subscription::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', Subscription::STATUS_PAST_DUE)
            ->update(['status' => Subscription::STATUS_ACTIVE]);

        if ($organization->isSuspended() && $organization->suspension_source === Organization::SUSPENDED_BY_BILLING) {
            $organization->reactivate();
            Audit::record('organization.reactivated', $organization, ['reason' => 'payment received'], $organization);
        }
    }

    private function nextInvoiceNumber(): string
    {
        $prefix = config('billing.invoice_prefix').'-'.now()->format('Ym').'-';
        $last = Invoice::withoutGlobalScopes()->where('number', 'like', $prefix.'%')->lockForUpdate()->max('number');
        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
