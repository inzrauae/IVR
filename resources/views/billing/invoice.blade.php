<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->number }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --brand: #2563eb; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: var(--ink); font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .page { max-width: 800px; margin: 32px auto; background: #fff; padding: 48px; border-radius: 12px; box-shadow: 0 1px 3px rgb(0 0 0 / .08); }
        .toolbar { max-width: 800px; margin: 24px auto 0; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar button, .toolbar a { font: inherit; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--line); background: #fff; color: var(--ink); cursor: pointer; text-decoration: none; }
        .toolbar button { background: var(--brand); border-color: var(--brand); color: #fff; }
        header { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; }
        h1 { margin: 0; font-size: 28px; letter-spacing: -.02em; }
        .muted { color: var(--muted); }
        .status { display: inline-block; margin-top: 6px; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .status.paid { background: #dcfce7; color: #166534; } .status.open { background: #dbeafe; color: #1e40af; }
        .status.overdue { background: #fee2e2; color: #991b1b; } .status.void { background: #f1f5f9; color: #475569; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 36px 0; }
        .label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); border-bottom: 1px solid var(--line); padding: 8px 0; }
        td { padding: 12px 0; border-bottom: 1px solid var(--line); vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .totals { margin-left: auto; width: 280px; margin-top: 16px; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .totals .grand { border-top: 2px solid var(--ink); margin-top: 6px; padding-top: 10px; font-weight: 700; font-size: 16px; }
        .note { margin-top: 36px; padding: 16px; background: #f8fafc; border-radius: 8px; white-space: pre-line; }
        @media (max-width: 640px) { .page { margin: 0; padding: 24px; border-radius: 0; } .parties { grid-template-columns: 1fr; } header { flex-direction: column; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { box-shadow: none; margin: 0; max-width: none; } }
    </style>
</head>
<body>
@php
    $money = fn ($v) => $invoice->currency.' '.number_format((float) $v, 2);
    $status = $invoice->isOverdue() ? 'overdue' : $invoice->status;
    $paid = $invoice->payments->sum('amount');
@endphp
<div class="toolbar">
    <a href="{{ route('billing.index') }}">Back to billing</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>
<main class="page">
    <header>
        <div>
            <h1>Invoice</h1>
            <div class="muted">{{ $invoice->number }}</div>
            <span class="status {{ $status }}">{{ $status }}</span>
        </div>
        <div style="text-align:right">
            <strong>{{ $seller['name'] }}</strong>
            @if ($seller['address'])<div class="muted" style="white-space:pre-line">{{ $seller['address'] }}</div>@endif
            @if ($seller['email'])<div class="muted">{{ $seller['email'] }}</div>@endif
        </div>
    </header>

    <section class="parties">
        <div>
            <div class="label">Billed to</div>
            <strong>{{ $organization->name }}</strong>
            @if ($organization->address)<div class="muted" style="white-space:pre-line">{{ $organization->address }}</div>@endif
            <div class="muted">{{ $organization->billing_email ?? $organization->contact_email }}</div>
        </div>
        <div>
            <div class="label">Issued</div>
            <div>{{ $invoice->issued_at->toFormattedDateString() }}</div>
            <div class="label" style="margin-top:10px">Due</div>
            <div>{{ $invoice->due_at->toFormattedDateString() }}</div>
            @if ($invoice->paid_at)
                <div class="label" style="margin-top:10px">Paid</div>
                <div>{{ $invoice->paid_at->toFormattedDateString() }}</div>
            @endif
        </div>
    </section>

    <table>
        <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
        <tbody>
        @foreach ($invoice->line_items as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td class="num">{{ $money($line['unit_price']) }}</td>
                <td class="num">{{ $money($line['amount']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div><span class="muted">Subtotal</span><span class="num">{{ $money($invoice->subtotal) }}</span></div>
        @if ((float) $invoice->tax > 0)
            <div><span class="muted">Tax</span><span class="num">{{ $money($invoice->tax) }}</span></div>
        @endif
        <div class="grand"><span>Total</span><span class="num">{{ $money($invoice->total) }}</span></div>
        @if ($paid > 0)
            <div><span class="muted">Paid</span><span class="num">−{{ $money($paid) }}</span></div>
            <div><strong>Balance due</strong><strong class="num">{{ $money(max(0, $invoice->total - $paid)) }}</strong></div>
        @endif
    </div>

    @if ($invoice->status === 'open' && $seller['bank_details'])
        <div class="note"><div class="label">How to pay</div>{{ $seller['bank_details'] }}
Please use {{ $invoice->number }} as the payment reference.</div>
    @endif
</main>
</body>
</html>
