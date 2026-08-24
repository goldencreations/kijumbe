<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'kijumbe:reconcile-payments {--limit=100}';

    protected $description = 'Query the payment provider for callbacks that may have been delayed or lost.';

    public function handle(PaymentReconciliationService $service): int
    {
        if (! config('services.payments.live_enabled')) {
            $this->warn('Real payments are disabled; nothing was reconciled.');

            return self::SUCCESS;
        }

        $count = $service->reconcile(max(1, (int) $this->option('limit')));
        $this->info("Reconciled {$count} terminal payment or payout event(s).");

        return self::SUCCESS;
    }
}
