<?php

namespace App\Console\Commands;

use App\Enums\CycleStatus;
use App\Models\Cycle;
use App\Services\CycleCompletionNotifier;
use Illuminate\Console\Command;

class NotifyCycleCompletions extends Command
{
    protected $signature = 'kijumbe:notify-cycle-completions';

    protected $description = 'Notify all opted-in members when every round in a mchezo has been paid.';

    public function handle(CycleCompletionNotifier $notifier): int
    {
        $notified = 0;

        Cycle::query()
            ->where('status', CycleStatus::Completed->value)
            ->whereNull('completion_notified_at')
            ->chunkById(50, function ($cycles) use ($notifier, &$notified): void {
                foreach ($cycles as $cycle) {
                    $notified += $notifier->notify($cycle) ? 1 : 0;
                }
            });

        $this->info("Notified members about {$notified} completed cycle(s).");

        return self::SUCCESS;
    }
}
