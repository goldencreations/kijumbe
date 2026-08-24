<?php

namespace App\Console\Commands;

use App\Enums\CycleStatus;
use App\Enums\RoundStatus;
use App\Models\Round;
use App\Services\AuditService;
use App\Services\RoundStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshRoundStatuses extends Command
{
    protected $signature = 'kijumbe:refresh-round-statuses';

    protected $description = 'Move unpaid rounds into grace or blocked state and release fully funded rounds.';

    public function handle(RoundStateService $states, AuditService $audit): int
    {
        $changed = 0;

        Round::query()
            ->whereHas('cycle', fn ($query) => $query->whereIn('status', [
                CycleStatus::Active->value,
                CycleStatus::Blocked->value,
            ]))
            ->whereNotIn('status', [
                RoundStatus::ReadyForPayout->value,
                RoundStatus::Paid->value,
                RoundStatus::Cancelled->value,
            ])
            ->chunkById(100, function ($rounds) use ($states, $audit, &$changed) {
                foreach ($rounds as $round) {
                    DB::transaction(function () use ($round, $states, $audit, &$changed) {
                        $locked = Round::query()->lockForUpdate()->findOrFail($round->id);
                        $before = $locked->attributesToArray();
                        $after = $states->refresh($locked);

                        if ($before['status'] !== $after->status->value || $before['collected_amount'] !== $after->collected_amount) {
                            $audit->record('round.status_refreshed', $after, null, $before, $after->attributesToArray());
                            $changed++;
                        }
                    }, 3);
                }
            });

        $this->info("Refreshed round states; {$changed} record(s) changed.");

        return self::SUCCESS;
    }
}
