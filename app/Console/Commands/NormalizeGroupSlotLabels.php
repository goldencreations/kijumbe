<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\Whatsapp\GroupMemberFlowService;
use Illuminate\Console\Command;

class NormalizeGroupSlotLabels extends Command
{
    protected $signature = 'kijumbe:normalize-slot-labels {group : Group UUID or group code}';

    protected $description = 'Relabel each member\'s names in their actual payout order without changing payout positions.';

    public function handle(GroupMemberFlowService $members): int
    {
        $value = trim((string) $this->argument('group'));
        $group = Group::query()
            ->whereKey($value)
            ->orWhere('group_code', strtoupper($value))
            ->first();

        if (! $group) {
            $this->error('Group not found.');

            return self::FAILURE;
        }

        $changed = $members->normalizeSlotLabels($group);
        $this->info("Normalized {$changed} slot label(s) for {$group->name}.");

        return self::SUCCESS;
    }
}
