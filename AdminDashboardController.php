<?php

namespace App\Http\Controllers;

use App\Enums\GroupStatus;
use App\Enums\ContributionStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $query = trim((string) $request->string('q', ''));
        $status = trim((string) $request->string('status', ''));

        $groupsQuery = Group::query()
            ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('is_active', true)])
            ->with(['creator'])
            ->latest();

        if ($query !== '') {
            $groupsQuery->where(function ($q) use ($query) {
                $q->where('name', 'like', '%'.$query.'%')
                    ->orWhere('id', 'like', '%'.$query.'%')
                    ->orWhere('created_by_phone', 'like', '%'.$query.'%');
            });
        }

        if (in_array($status, ['draft', 'active', 'archived'], true)) {
            $groupsQuery->where('status', $status);
        }

        $groups = $groupsQuery->limit(8)->get();

        $selectedGroup = $groups->first() ?? Group::query()
            ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('is_active', true)])
            ->with(['creator', 'memberships.user', 'cycles.rounds'])
            ->latest()
            ->first();

        if ($selectedGroup) {
            $selectedGroup->load([
                'memberships.user',
                'cycles' => fn ($query) => $query->latest()->limit(3),
            ]);
        }

        $activeGroups = Group::query()->where('status', GroupStatus::Active->value)->count();
        $draftGroups = Group::query()->where('status', GroupStatus::Draft->value)->count();
        $archivedGroups = Group::query()->where('status', GroupStatus::Archived->value)->count();
        $members = User::query()->where('is_active', true)->count();
        $memberships = GroupMember::query()->where('is_active', true)->count();
        $pendingContributions = Contribution::query()->where('status', ContributionStatus::Pending->value)->count();
        $paidContributions = Contribution::query()->whereIn('status', [ContributionStatus::Paid->value, ContributionStatus::PaidLate->value])->count();

        return view('admin.dashboard', [
            'groups' => $groups,
            'selectedGroup' => $selectedGroup,
            'filters' => [
                'query' => $query,
                'status' => $status,
            ],
            'stats' => [
                'activeGroups' => $activeGroups,
                'draftGroups' => $draftGroups,
                'archivedGroups' => $archivedGroups,
                'members' => $members,
                'memberships' => $memberships,
                'pendingContributions' => $pendingContributions,
                'paidContributions' => $paidContributions,
                'collectionValue' => 'TZS 128,450,000',
                'pendingValue' => 'TZS 18,240,000',
                'payoutValue' => 'TZS 94,800,000',
                'activityRate' => '84%',
            ],
            'statusBreakdown' => [
                ['label' => 'Active', 'value' => $activeGroups, 'tone' => 'emerald'],
                ['label' => 'Draft', 'value' => $draftGroups, 'tone' => 'amber'],
                ['label' => 'Archived', 'value' => $archivedGroups, 'tone' => 'slate'],
            ],
        ]);
    }
}
