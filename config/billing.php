<?php

return [

    // Tax applied to invoices, as a percentage (e.g. 18 for 18% VAT). 0 disables tax lines.
    'tax_rate' => (float) env('BILLING_TAX_RATE', 0),

    'tax_label' => env('BILLING_TAX_LABEL', 'VAT'),

    // Days an invoice is payable after issue.
    'payment_terms_days' => (int) env('BILLING_PAYMENT_TERMS_DAYS', 7),

    // Days after the due date before an unpaid organization is suspended.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),

    // Shown on invoices.
    'seller' => [
        'name' => env('BILLING_SELLER_NAME', env('APP_NAME', 'CloudPBX')),
        'address' => env('BILLING_SELLER_ADDRESS'),
        'email' => env('BILLING_SELLER_EMAIL'),
        'bank_details' => env('BILLING_BANK_DETAILS'),
    ],

];
