<?php

namespace App\Console\Commands;

use App\Billing\BillingService;
use Illuminate\Console\Command;

class RunBilling extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Issue renewal invoices, flag overdue accounts and suspend after the grace period';

    public function handle(BillingService $billing): int
    {
        $stats = $billing->run();

        $this->info("Invoiced {$stats['invoiced']}, marked past due {$stats['past_due']}, suspended {$stats['suspended']}.");

        return self::SUCCESS;
    }
}
