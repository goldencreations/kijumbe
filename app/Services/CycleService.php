<?php

namespace App\Services;

use App\Enums\CycleMemberStatus;
use App\Enums\CycleStatus;
use App\Enums\GroupStatus;
use App\Enums\RoundStatus;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\CycleMember;
use App\Models\Group;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CycleService
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly AuditService $audit,
    ) {}

    public function create(User $actor, Group $group, array $data): Cycle
    {
        $this->access->ensureManager($actor, $group);

        if ($group->status !== GroupStatus::Active) {
            throw new ConflictHttpException('A cycle cannot be created for an archived group.');
        }

        return DB::transaction(function () use ($actor, $group, $data) {
            $memberRows = collect($data['members']);
            $groupMemberIds = $memberRows->pluck('group_member_id');

            $activeCount = $group->memberships()
                ->whereIn('id', $groupMemberIds)
                ->where('is_active', true)
                ->count();

            if ($activeCount !== $groupMemberIds->unique()->count()) {
                throw new ConflictHttpException('Every cycle member must be a unique, active member of this group.');
            }

            $cycle = Cycle::create([
                'group_id' => $group->id,
                'name' => $data['name'],
                'contribution_amount' => $data['contribution_amount'],
                'currency' => 'TZS',
                'period_days' => $data['period_days'],
                'grace_period_hours' => $data['grace_period_hours'],
                'starts_at' => $data['starts_at'],
                'member_count' => $memberRows->count(),
                'status' => CycleStatus::Draft,
            ]);

            foreach ($memberRows as $member) {
                CycleMember::create([
                    'cycle_id' => $cycle->id,
                    'group_member_id' => $member['group_member_id'],
                    'payout_position' => $member['payout_position'],
                    'status' => CycleMemberStatus::Active,
                ]);
            }

            $this->audit->record('cycle.created', $cycle, $actor, after: $cycle->attributesToArray());

            return $cycle->load('members.groupMember.user');
        }, 3);
    }

    public function activate(User $actor, Cycle $cycle): Cycle
    {
        $this->access->ensureManager($actor, $cycle->group);

        return DB::transaction(function () use ($actor, $cycle) {
            $cycle = Cycle::query()->lockForUpdate()->findOrFail($cycle->id);

            if ($cycle->status !== CycleStatus::Draft) {
                throw new ConflictHttpException('Only a draft cycle can be activated.');
            }

            $members = $cycle->members()->orderBy('payout_position')->get();
            if ($members->count() < 2) {
                throw new ConflictHttpException('An office pilot cycle needs at least two members.');
            }

            $expectedPositions = range(1, $members->count());
            if ($members->pluck('payout_position')->all() !== $expectedPositions) {
                throw new ConflictHttpException('Payout positions must be unique and consecutive, starting at 1.');
            }

            $requiredAmount = $cycle->contribution_amount * $members->count();

            foreach ($members as $index => $recipient) {
                $dueAt = $cycle->starts_at->copy()->addDays($index * $cycle->period_days);
                $graceEndsAt = $dueAt->copy()->addHours($cycle->grace_period_hours);
                $round = Round::create([
                    'cycle_id' => $cycle->id,
                    'number' => $index + 1,
                    'recipient_cycle_member_id' => $recipient->id,
                    'due_at' => $dueAt,
                    'grace_ends_at' => $graceEndsAt,
                    'required_amount' => $requiredAmount,
                    'collected_amount' => 0,
                    'payout_amount' => 0,
                    'status' => now()->lt($dueAt) ? RoundStatus::Scheduled : RoundStatus::Collecting,
                ]);

                foreach ($members as $member) {
                    Contribution::create([
                        'round_id' => $round->id,
                        'cycle_member_id' => $member->id,
                        'amount' => $cycle->contribution_amount,
                        'due_at' => $dueAt,
                        'grace_ends_at' => $graceEndsAt,
                    ]);
                }
            }

            $before = $cycle->attributesToArray();
            $cycle->update([
                'status' => CycleStatus::Active,
                'member_count' => $members->count(),
                'activated_at' => now(),
            ]);

            $this->audit->record('cycle.activated', $cycle, $actor, $before, $cycle->fresh()->attributesToArray());

            return $cycle->fresh()->load([
                'members.groupMember.user',
                'rounds.recipient.groupMember.user',
                'rounds.contributions.cycleMember.groupMember.user',
            ]);
        }, 3);
    }
}
