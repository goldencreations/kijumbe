<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContributionStatus;
use App\Enums\CycleStatus;
use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Http\Controllers\Controller;
use App\Models\Cycle;
use App\Models\Group;
use App\Models\MemberPaymentObligation;
use App\Services\GroupDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Group::query()
            ->with('creator:id,name,email,phone')
            ->withCount([
                'memberships as active_member_count' => fn ($query) => $query->where('is_active', true),
                'cycles as cycle_count',
            ])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('group_type'), function ($query) use ($request): void {
                $type = $request->string('group_type')->toString();
                if (in_array($type, [Group::TYPE_MONEY, Group::TYPE_PRODUCT], true)) {
                    $query->where('group_type', $type);
                }
            })
            ->when($request->filled('created_by'), fn ($query) => $query->where('created_by', $request->integer('created_by')))
            ->when($request->filled('cycle_status'), fn ($query) => $query->whereHas('cycles', fn ($query) => $query->where('status', $request->string('cycle_status')->toString())))
            ->when($request->filled('starts_from'), fn ($query) => $query->where('cycle_starts_at', '>=', $request->date('starts_from')))
            ->when($request->filled('starts_to'), fn ($query) => $query->where('cycle_starts_at', '<=', $request->date('starts_to')))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where('name', 'like', $search)->orWhere('group_code', 'like', $search);
            }))
            ->orderBy(
                in_array($request->input('sort'), ['name', 'status', 'created_at', 'cycle_starts_at'], true)
                    ? $request->input('sort')
                    : 'created_at',
                $request->input('direction') === 'asc' ? 'asc' : 'desc',
            );

        return response()->json($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    public function show(Group $group): JsonResponse
    {
        return response()->json(['data' => [
            'group' => $group->load('creator:id,name,email,phone'),
            'statistics' => $this->buildStatistics($group),
            'members' => $this->buildMembers($group),
            'cycles' => $group->cycles()->latest()->get(),
        ]]);
    }

    public function destroy(Request $request, Group $group, GroupDeletionService $deletion): JsonResponse
    {
        $id = $deletion->delete($group, $request->user());

        return response()->json(['data' => ['id' => $id]]);
    }

    public function members(Group $group): JsonResponse
    {
        return response()->json(['data' => $this->buildMembers($group)]);
    }

    public function statistics(Group $group): JsonResponse
    {
        return response()->json(['data' => $this->buildStatistics($group)]);
    }

    public function rounds(Request $request, Group $group): JsonResponse
    {
        $cycles = $group->cycles()
            ->when($request->filled('cycle_id'), fn ($query) => $query->whereKey($request->input('cycle_id')))
            ->with([
                'rounds' => fn ($query) => $query->with([
                    'recipient.groupMember.user:id,name,phone,whatsapp_wa_id',
                    'contributions.cycleMember.groupMember.user:id,name,phone,whatsapp_wa_id',
                    'payout',
                ])->orderBy('number'),
            ])
            ->get();

        return response()->json(['data' => $cycles->flatMap(function (Cycle $cycle): array {
            return $cycle->rounds->map(fn ($round): array => [
                'cycle' => $cycle->only(['id', 'name', 'status', 'starts_at', 'completed_at']),
                'round' => $round,
                'recipient' => $round->recipient?->groupMember?->load('user'),
                'contributions' => $round->contributions,
                'payout' => $round->payout,
            ])->all();
        })->values()]);
    }

    public function payoutOrder(Request $request, Group $group): JsonResponse
    {
        $cycle = $group->cycles()
            ->when($request->filled('cycle_id'), fn ($query) => $query->whereKey($request->input('cycle_id')))
            ->latest()
            ->firstOrFail();

        $members = $cycle->members()
            ->with(['groupMember.user:id,name,phone,whatsapp_wa_id', 'recipientRounds.payout'])
            ->orderBy('payout_position')
            ->get()
            ->map(fn ($member): array => [
                'position' => $member->payout_position,
                'cycle_member_id' => $member->id,
                'member' => $member->groupMember,
                'received_rounds' => $member->recipientRounds
                    ->filter(fn ($round) => $round->payout?->status === PayoutStatus::Paid)
                    ->values(),
                'pending_rounds' => $member->recipientRounds
                    ->reject(fn ($round) => $round->payout?->status === PayoutStatus::Paid)
                    ->values(),
                'received_count' => $member->recipientRounds
                    ->filter(fn ($round) => $round->payout?->status === PayoutStatus::Paid)
                    ->count(),
            ]);

        return response()->json(['data' => [
            'cycle' => $cycle->only(['id', 'name', 'status', 'starts_at', 'completed_at']),
            'order' => $members,
        ]]);
    }

    /** @return array<string, mixed> */
    private function buildStatistics(Group $group): array
    {
        $cycles = $group->cycles()
            ->with(['rounds.contributions', 'rounds.payout'])
            ->get();
        $rounds = $cycles->flatMap->rounds;
        $contributions = $rounds->flatMap->contributions;
        $payouts = $rounds->pluck('payout')->filter();

        return [
            'members' => [
                'active' => $group->memberships()->where('is_active', true)->count(),
                'slots' => $group->slots()->where('status', 'active')->count(),
            ],
            'cycles' => [
                'total' => $cycles->count(),
                'active' => $cycles->where('status', CycleStatus::Active)->count(),
                'completed' => $cycles->where('status', CycleStatus::Completed)->count(),
                'blocked' => $cycles->where('status', CycleStatus::Blocked)->count(),
            ],
            'rounds' => [
                'total' => $rounds->count(),
                'paid' => $rounds->where('status', RoundStatus::Paid)->count(),
                'ready_for_payout' => $rounds->where('status', RoundStatus::ReadyForPayout)->count(),
                'blocked' => $rounds->where('status', RoundStatus::Blocked)->count(),
            ],
            'contributions' => [
                'total' => $contributions->count(),
                'paid' => $contributions->whereIn('status', [ContributionStatus::Paid, ContributionStatus::PaidLate])->count(),
                'pending' => $contributions->where('status', ContributionStatus::Pending)->count(),
                'collected_amount' => (int) $contributions
                    ->whereIn('status', [ContributionStatus::Paid, ContributionStatus::PaidLate])
                    ->sum('amount'),
                'expected_amount' => (int) $contributions->sum('amount'),
            ],
            'payouts' => [
                'total' => $payouts->count(),
                'paid' => $payouts->where('status', PayoutStatus::Paid)->count(),
                'pending' => $payouts->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Processing])->count(),
                'paid_amount' => (int) $payouts->where('status', PayoutStatus::Paid)->sum('amount'),
            ],
            'outstanding_ledger_amount' => (int) MemberPaymentObligation::query()
                ->where('group_id', $group->id)
                ->selectRaw('COALESCE(SUM(GREATEST(principal_amount + kijumbe_fee_amount + penalty_amount - paid_amount, 0)), 0) as total')
                ->value('total'),
        ];
    }

    /** @return array<int, mixed> */
    private function buildMembers(Group $group): array
    {
        return $group->memberships()
            ->with(['user:id,name,email,phone,whatsapp_wa_id', 'slots'])
            ->where('is_active', true)
            ->orderBy('joined_at')
            ->get()
            ->map(fn ($member): array => [
                'membership' => $member,
                'slots_count' => $member->slots->where('status', 'active')->count(),
                'payout_positions' => $member->slots->where('status', 'active')->pluck('payout_position')->values(),
            ])->all();
    }
}
