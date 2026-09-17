<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\MemberPaymentLedgerService;
use App\Services\MichezoPayoutSyncService;
use Illuminate\Console\Command;

class SyncMichezoPayouts extends Command
{
    protected $signature = 'kijumbe:sync-michezo-payouts {--group= : Sync one group UUID only}';

    protected $description = 'Synchronize member ledger payments into payout rounds using confirmed principal only.';

    public function handle(MemberPaymentLedgerService $ledger, MichezoPayoutSyncService $payouts): int
    {
        $groups = Group::query()
            ->whereIn('status', ['active', 'draft'])
            ->whereNotNull('cycle_starts_at')
            ->when($this->option('group'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $rounds = 0;
        foreach ($groups as $group) {
            $ledger->materialize($group);
            $rounds += $payouts->sync($group);
        }

        $this->info("Synchronized {$rounds} payout round(s) across {$groups->count()} group(s).");

        return self::SUCCESS;
    }
}
